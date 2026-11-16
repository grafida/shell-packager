<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\Linux\AppImageConfig;
use Grafida\ShellPackager\Linux\Squashfs;
use Grafida\ShellPackager\PackagerException;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

/**
 * grafida/shell-packager#1: which mksquashfs an AppImage is made with. The tools are fakes on a
 * PATH of their own: `mksquashfs` prints a version, `docker` logs its arguments and answers as
 * the test tells it to.
 */
#[RequiresOperatingSystem('^(?!WIN)')]
final class SquashfsTest extends TestCase
{
    private const IMAGE = 'example.invalid/squashfs@sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private string $dir;

    private string $path;

    /** @var list<string> */
    private array $said = [];

    protected function setUp(): void
    {
        $this->dir  = Filesystem::tempDir('squashfs-test');
        $this->path = (string) getenv('PATH');
        Filesystem::mkdir($this->dir . '/bin');
        putenv('PATH=' . $this->dir . '/bin');
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->path);
        Filesystem::remove($this->dir);
    }

    public function testTheConfigurationIsValidated(): void
    {
        self::assertSame('auto', AppImageConfig::fromArray([])->squashfs);
        self::assertNull(AppImageConfig::fromArray([])->dockerImage);
        $config = AppImageConfig::fromArray(['squashfs' => 'docker', 'docker' => ['image' => self::IMAGE]]);
        self::assertSame('docker', $config->squashfs);
        self::assertSame(self::IMAGE, $config->dockerImage);

        $bad = [
            ['squashfs' => 'podman'],
            ['squashfs' => true],
            ['docker' => ['image' => 'alpine:latest']],
            ['docker' => ['image' => 'alpine']],
            ['docker' => ['image' => 'alpine@sha256:abc']],
            ['docker' => ['image' => self::IMAGE, 'tag' => 'x']],
            ['docker' => 'alpine'],
        ];

        foreach ($bad as $data) {
            try {
                AppImageConfig::fromArray($data);
                self::fail('accepted ' . json_encode($data));
            } catch (PackagerException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAutoPrefersTheHostMksquashfs(): void
    {
        $this->tool('mksquashfs', 'echo "mksquashfs version 4.7.5 (2026/03/01)"');
        $this->docker();

        $squashfs = $this->resolve('auto');

        self::assertSame('host', $squashfs->strategy);
        self::assertSame([], $this->calls(), 'docker is not consulted');
        self::assertSame(['squashfs: host, mksquashfs version 4.7.5 (2026/03/01)'], $this->said);
    }

    public function testAutoFallsBackToDockerAndBuildsItsImageOnce(): void
    {
        $this->docker(imagePresent: false);

        $squashfs = $this->resolve('auto');

        self::assertSame('docker', $squashfs->strategy);
        $tag = Squashfs::LOCAL_IMAGE . ':' . substr(hash('sha256', Filesystem::read(Squashfs::dockerfile())), 0, 16);
        self::assertSame($tag, $squashfs->image);
        self::assertSame([
            'version --format {{.Server.Version}}',
            "image inspect {$tag}",
            "build --tag {$tag} -",
            "run --rm --network none {$tag} mksquashfs -version",
        ], $this->calls());
        self::assertSame(Filesystem::read(Squashfs::dockerfile()), Filesystem::read($this->dir . '/stdin'), 'the Dockerfile is the whole build context');
        self::assertStringContainsString('Building', $this->said[0]);
    }

    public function testAConfiguredImageIsPulledByDigestAndChecked(): void
    {
        $this->docker(imagePresent: false);

        $squashfs = $this->resolve('docker', self::IMAGE);

        self::assertSame(self::IMAGE, $squashfs->image);
        self::assertSame([
            'version --format {{.Server.Version}}',
            'image inspect ' . self::IMAGE,
            'pull ' . self::IMAGE,
            'run --rm --network none ' . self::IMAGE . ' mksquashfs -version',
        ], $this->calls());
    }

    public function testAnImageWithoutTarInputIsRefused(): void
    {
        $this->docker(version: 'mksquashfs version 4.5 (2021/07/22)');
        $this->expectExceptionMessage('needs mksquashfs 4.6 or later');
        $this->resolve('docker', self::IMAGE);
    }

    public function testDockerWhoseDaemonIsDownIsReportedAsSuch(): void
    {
        $this->docker(daemon: false);

        try {
            $this->resolve('auto');
            self::fail('no error');
        } catch (PackagerException $e) {
            self::assertStringContainsString('not on PATH', $e->getMessage());
            self::assertStringContainsString("Docker's daemon is not answering", $e->getMessage());
            self::assertStringContainsString('Cannot connect to the Docker daemon', $e->getMessage());
        }
    }

    public function testNothingAtAllNamesBothWays(): void
    {
        try {
            $this->resolve('auto');
            self::fail('no error');
        } catch (PackagerException $e) {
            self::assertStringContainsString('neither it nor docker', $e->getMessage());
            self::assertStringContainsString('brew install squashfs', $e->getMessage());
            self::assertStringContainsString('install Docker', $e->getMessage());
        }
    }

    public function testAForcedStrategyDoesNotFallBack(): void
    {
        $this->docker();

        try {
            $this->resolve('host');
            self::fail('host without mksquashfs');
        } catch (PackagerException $e) {
            self::assertStringContainsString('"host", and mksquashfs was not found', $e->getMessage());
        }

        Filesystem::remove($this->dir . '/bin/docker');
        $this->tool('mksquashfs', 'echo "mksquashfs version 4.7.5"');
        $this->expectExceptionMessage('"docker", and the docker command was not found');
        $this->resolve('docker');
    }

    private function resolve(string $strategy, ?string $image = null): Squashfs
    {
        return Squashfs::resolve(new AppImageConfig(squashfs: $strategy, dockerImage: $image), function (string $line): void {
            $this->said[] = $line;
        });
    }

    /** A fake `docker`: logs each call; `image inspect` answers $imagePresent, `version` $daemon. */
    private function docker(bool $daemon = true, bool $imagePresent = true, string $version = 'mksquashfs version 4.7.5 (2026/03/01)'): void
    {
        $log   = escapeshellarg($this->dir . '/calls');
        $stdin = escapeshellarg($this->dir . '/stdin');
        $this->tool('docker', <<<SH
            printf '%s\\n' "\$*" >> {$log}
            case "\$1" in
              version) {$this->answer($daemon, '27.3.1', 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock.')} ;;
              image) {$this->answer($imagePresent, '[{}]', 'Error: No such image')} ;;
              build) /bin/cat > {$stdin} ;;
              pull) ;;
              run) echo "{$version}" ;;
              *) exit 99 ;;
            esac
            SH);
    }

    private function answer(bool $ok, string $out, string $err): string
    {
        return $ok ? "echo '{$out}'" : "echo '{$err}' >&2; exit 1";
    }

    /** @return list<string> */
    private function calls(): array
    {
        $log = $this->dir . '/calls';

        return is_file($log) ? array_values(array_filter(explode("\n", Filesystem::read($log)), static fn (string $line): bool => $line !== '')) : [];
    }

    private function tool(string $name, string $body): void
    {
        Filesystem::write("{$this->dir}/bin/{$name}", "#!/bin/sh\n{$body}\n");
        chmod("{$this->dir}/bin/{$name}", 0o755);
    }
}
