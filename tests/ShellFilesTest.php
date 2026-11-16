<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\Arch;
use Grafida\ShellPackager\Config;
use Grafida\ShellPackager\Fetcher;
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Platform;
use Grafida\ShellPackager\Shell\Release;
use Grafida\ShellPackager\Shell\ReleaseAsset;
use Grafida\ShellPackager\Shell\ReleaseSource;
use Grafida\ShellPackager\Shell\ShellAssets;
use Grafida\ShellPackager\Shell\ShellConfig;
use Grafida\ShellPackager\Shell\ShellFiles;
use PHPUnit\Framework\TestCase;

/**
 * Where each shell file comes from: a local file, or the lock (written on the first build, or by
 * update). The release is served from file:// URLs, so nothing here touches the network.
 */
final class ShellFilesTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $said = [];

    private int $listings = 0;

    /**
     * The repository in the fake release's URLs, as GitHub spells it. The files are already in the
     * cache, so nothing is downloaded (a digest mismatch on download is FetcherTest's).
     */
    private string $served = 'grafida/shell';

    protected function setUp(): void
    {
        // Real: the configuration's root is, and local paths are made from it.
        $this->dir = (string) realpath(Filesystem::tempDir('shell-files-test'));
        Filesystem::write($this->dir . '/app/shell.json', Filesystem::read(Fixture::APP . '/shell.json'));

        foreach (self::names() as $name) {
            Filesystem::write($this->dir . '/release/' . $name, "the {$name} file");
            Filesystem::write($this->dir . '/app/local/' . $name, "a local {$name}");
            Filesystem::write($this->dir . '/cache/shell/grafida/shell/0.4.2/' . $name, "the {$name} file");
        }
    }

    protected function tearDown(): void
    {
        Filesystem::remove($this->dir);
    }

    /** @return list<string> */
    private static function names(): array
    {
        $names = [];

        foreach (ShellConfig::fromArray(['version' => '*'], __DIR__)->assets as $components) {
            foreach ($components as $byArch) {
                array_push($names, ...array_values($byArch));
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $shell */
    private function config(array $shell = ['version' => '^0.4']): Config
    {
        Filesystem::write($this->dir . '/app/shell-package.json', (string) json_encode([
            'manifest' => 'shell.json',
            'phar'     => ['entrypoint' => 'bin/worker.php', 'include' => [['directory' => 'bin']]],
            'shell'    => $shell,
        ]));

        return Config::load($this->dir . '/app/shell-package.json');
    }

    private function source(): ReleaseSource
    {
        $assets = [];

        foreach (self::names() as $name) {
            $assets[$name] = new ReleaseAsset($name, "https://github.com/{$this->served}/releases/download/0.4.2/{$name}", hash('sha256', "the {$name} file"));
        }

        $releases = [new Release('0.4.2', false, $assets)];
        $this->listings++;

        return new class ($releases) implements ReleaseSource {
            /** @param list<Release> $releases */
            public function __construct(private array $releases)
            {
            }

            public function releases(string $repository): array
            {
                return $this->releases;
            }

            public function describe(): string
            {
                return 'a fake';
            }
        };
    }

    private function files(Config $config, ?Fetcher $fetcher = null, bool $offline = false): ShellFiles
    {
        $source = $offline
            ? static fn (): ReleaseSource => throw new \LogicException('The GitHub API was called.')
            : fn (): ReleaseSource => $this->source();

        return new ShellFiles($config, $fetcher ?? new Fetcher($this->dir . '/cache'), $source, function (string $line): void {
            $this->said[] = $line;
        });
    }

    private function lockPath(): string
    {
        return $this->dir . '/app/shell-package.lock';
    }

    public function testNoLockResolvesWritesTheLockAndContinues(): void
    {
        $path = $this->files($this->config())->path(Platform::Linux, 'shell', Arch::X86_64);

        self::assertSame($this->dir . '/cache/shell/grafida/shell/0.4.2/grafida-shell-linux-x86_64', $path);
        self::assertSame('the grafida-shell-linux-x86_64 file', Filesystem::read($path));
        self::assertFileExists($this->lockPath());
        self::assertSame(1, $this->listings);
        self::assertNotEmpty(array_filter($this->said, static fn (string $l): bool => str_contains($l, 'commit it')));
    }

    public function testABuildFromTheLockMakesNoApiCall(): void
    {
        $this->files($this->config())->update();
        $files = $this->files($this->config(), offline: true);

        foreach (ShellAssets::SLOTS as $platform => $components) {
            foreach ($components as $component => $arches) {
                foreach ($arches as $arch) {
                    self::assertFileExists($files->path(Platform::from($platform), $component, Arch::from($arch)));
                }
            }
        }

        self::assertSame(1, $this->listings);
    }

    public function testAStaleLockFails(): void
    {
        $this->files($this->config())->update();

        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('shell-packager update');
        $this->files($this->config(['version' => '^0.5']), offline: true)->path(Platform::Linux, 'shell', Arch::X86_64);
    }

    public function testLocalFilesNeedNoLock(): void
    {
        $config = $this->config(['version' => '^0.4', 'local' => ['linux' => [
            'shell'    => 'local/grafida-shell-linux-{arch}',
            'launcher' => 'local/grafida-launcher-linux-{arch}',
        ]]]);
        $files  = $this->files($config, offline: true);

        foreach (['shell', 'launcher'] as $component) {
            foreach (Arch::cases() as $arch) {
                self::assertSame(
                    // From the configuration's root, as the packager joins it (with \ on Windows, from realpath()).
                    $config->root . "/local/grafida-{$component}-linux-{$arch->value}",
                    $files->path(Platform::Linux, $component, $arch)
                );
            }
        }

        self::assertFileDoesNotExist($this->lockPath());

        // A file that is not local still comes from the lock, which is written for it.
        $path = $this->files($config)->path(Platform::Macos, 'shell', Arch::Aarch64);
        self::assertSame('the grafida-shell-macos-arm64 file', Filesystem::read($path));
        self::assertFileExists($this->lockPath());
    }

    public function testCliLocalWinsOverConfigLocal(): void
    {
        $config  = $this->config(['version' => '^0.4', 'local' => ['linux' => ['shell' => 'local/grafida-shell-linux-{arch}']]]);
        $fetcher = new Fetcher($this->dir . '/cache');
        $fetcher->useLocal('shell', $this->dir . '/release/grafida-shell-macos-arm64');

        self::assertSame($this->dir . '/release/grafida-shell-macos-arm64', $this->files($config, $fetcher, true)->path(Platform::Linux, 'shell', Arch::X86_64));
    }

    public function testUpdateFetchesAllSeven(): void
    {
        $lock = $this->files($this->config())->update();

        self::assertSame('0.4.2', $lock->version);
        self::assertCount(7, (array) glob($this->dir . '/cache/shell/grafida/shell/0.4.2/*'));
    }

    public function testARepositoryInOtherCapitalsStillBuilds(): void
    {
        // GitHub answers for Grafida/Shell, and its URLs say grafida/shell.
        foreach (self::names() as $name) {
            Filesystem::write($this->dir . '/cache/shell/Grafida/Shell/0.4.2/' . $name, "the {$name} file");
        }

        $config = $this->config(['version' => '^0.4', 'repository' => 'Grafida/Shell']);
        $this->files($config)->update();

        self::assertFileExists($this->files($config, offline: true)->path(Platform::Windows, 'shell', Arch::X86_64));
    }

    public function testARenamedRepositoryIsNamedAndNoLockIsWritten(): void
    {
        // The repository moved: GitHub redirects the listing, and its URLs carry the new name.
        $this->served = 'grafida/renamed';

        try {
            $this->files($this->config())->update();
            self::fail('A lock that would be refused on reading was written.');
        } catch (PackagerException $e) {
            self::assertStringContainsString('grafida/renamed', $e->getMessage());
            self::assertStringContainsString('shell.repository', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->lockPath());
    }

    public function testUpdateRewritesAnUnchangedLockByteForByte(): void
    {
        $this->files($this->config())->update();
        $first = Filesystem::read($this->lockPath());
        $this->said = [];

        $this->files($this->config())->update();

        self::assertSame($first, Filesystem::read($this->lockPath()));
        self::assertNotEmpty(array_filter($this->said, static fn (string $l): bool => str_contains($l, 'unchanged')));
    }

    public function testAHandEditedLockIsRefused(): void
    {
        $this->files($this->config())->update();
        $lock = Filesystem::read($this->lockPath());
        Filesystem::write($this->lockPath(), str_replace(
            'https://github.com/grafida/shell/releases/download/0.4.2/grafida-shell-linux-x86_64"',
            'https://evil.example/grafida-shell-linux-x86_64"',
            $lock
        ));

        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('does not match the configuration');
        $this->files($this->config(), offline: true)->path(Platform::Macos, 'shell', Arch::Aarch64);
    }
}
