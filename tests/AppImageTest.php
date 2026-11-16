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
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\Linux\AppImage;
use Grafida\ShellPackager\Linux\AppImageConfig;
use Grafida\ShellPackager\Linux\Squashfs;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

/**
 * grafida/grafida#87. The AppImage is checked structurally (it cannot be run on a Mac): the ELF
 * runtime, the type-2 marker, the squashfs after it, its listing and modes, and the update
 * information. Needs `mksquashfs` and `unsquashfs` (Homebrew `squashfs`, apt `squashfs-tools`).
 * SHELL_PACKAGER_TEST_APPIMAGE_RUNTIME names the real pinned runtime for the last test.
 */
#[RequiresOperatingSystem('^(?!WIN)')]
final class AppImageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Filesystem::tempDir('appimage-test');

        foreach (['launcher', 'shell', 'sfx', 'payload'] as $name) {
            Filesystem::write("{$this->dir}/{$name}", "the {$name}");
        }
    }

    protected function tearDown(): void
    {
        Filesystem::remove($this->dir);
    }

    public function testUpdateInformationIsWrittenIntoTheSectionAndNothingElseMoves(): void
    {
        $elf = ElfBuilder::runtime();
        $out = AppImage::embedUpdateInformation($elf, 'zsync|https://example.invalid/x.zsync');

        self::assertSame(\strlen($elf), \strlen($out));
        self::assertSame('zsync|https://example.invalid/x.zsync', AppImage::readUpdateInformation($out));
        self::assertNull(AppImage::readUpdateInformation($elf));
        $section = AppImage::findSection($elf, '.upd_info');
        self::assertNotNull($section);
        [$at, $size] = $section;
        self::assertSame(substr($elf, 0, $at), substr($out, 0, $at));
        self::assertSame(substr($elf, $at + $size), substr($out, $at + $size));
        $length = \strlen('zsync|https://example.invalid/x.zsync');
        self::assertSame(str_repeat("\0", $size - $length), substr($out, $at + $length, $size - $length), 'NUL padded');
    }

    public function testUpdateInformationThatDoesNotFitOrHasNowhereToGoIsRefused(): void
    {
        try {
            AppImage::embedUpdateInformation(ElfBuilder::runtime(16), 'zsync|' . str_repeat('a', 20));
            self::fail('too long');
        } catch (PackagerException $e) {
            self::assertStringContainsString('holds 16', $e->getMessage());
        }

        $this->expectExceptionMessage('no .upd_info section');
        AppImage::embedUpdateInformation(ElfBuilder::runtime(withSection: false), 'zsync|x');
    }

    public function testTheConfigurationValidatesUpdateInformation(): void
    {
        self::assertSame('zsync|https://e.invalid/a.zsync', AppImageConfig::fromArray(['updateInformation' => 'zsync|https://e.invalid/a.zsync'])->updateInformation);
        self::assertNull(AppImageConfig::fromArray([])->updateInformation);

        foreach (['https://e.invalid/a.zsync', 'zsync|has space', 'zsync|', 'zsync|' . str_repeat('a', 1024), 5] as $bad) {
            try {
                AppImageConfig::fromArray(['updateInformation' => $bad]);
                self::fail('accepted ' . json_encode($bad));
            } catch (PackagerException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * The docker strategy's input and output, without Docker: a fake `docker` drops everything up to
     * the image's `sh` and runs the rest here, so the host's mksquashfs reads the AppDir as a tar on
     * stdin and the image comes back on stdout, exactly as in the container. Same mksquashfs, same
     * options: the bytes must be the host strategy's.
     */
    public function testTheDockerRouteGivesTheHostStrategysBytes(): void
    {
        $this->needTools();

        if (!Squashfs::host()->tar) {
            self::markTestSkipped('This mksquashfs is older than 4.6 and reads no tar.');
        }

        $runtime = $this->dir . '/runtime';
        Filesystem::write($runtime, ElfBuilder::runtime());
        Filesystem::mkdir($this->dir . '/bin');
        Filesystem::write($this->dir . '/bin/docker', "#!/bin/sh\nwhile [ \"\$1\" != sh ]; do shift; done\nexec \"\$@\"\n");
        chmod($this->dir . '/bin/docker', 0o755);
        $path = (string) getenv('PATH');
        putenv("PATH={$this->dir}/bin:{$path}");

        try {
            $tar = $this->build($runtime, $this->dir . '/tar.AppImage', Squashfs::docker('fake'));
        } finally {
            putenv("PATH={$path}");
        }

        $host = $this->build($runtime, $this->dir . '/host.AppImage');
        self::assertSame(hash_file('sha256', $host), hash_file('sha256', $tar));
    }

    /**
     * The real thing, when SHELL_PACKAGER_TEST_DOCKER is set and a daemon answers: the image the
     * packager builds from its Dockerfile, and the same bytes as a host mksquashfs of the same
     * version (Homebrew's when the Dockerfile was pinned).
     */
    public function testTheDockerStrategy(): void
    {
        if (getenv('SHELL_PACKAGER_TEST_DOCKER') === false) {
            self::markTestSkipped('SHELL_PACKAGER_TEST_DOCKER runs mksquashfs in the packager\'s Docker image.');
        }

        $said     = [];
        $squashfs = Squashfs::resolve(new AppImageConfig(squashfs: 'docker'), static function (string $line) use (&$said): void {
            $said[] = $line;
        });
        $runtime  = $this->dir . '/runtime';
        Filesystem::write($runtime, ElfBuilder::runtime());
        $docker = $this->build($runtime, $this->dir . '/docker.AppImage', $squashfs);
        $offset = \strlen(ElfBuilder::runtime());
        self::assertSame('hsqs', substr(Filesystem::read($docker), $offset, 4));

        if (!Process::exists('mksquashfs')) {
            return;
        }

        $hostVersion = explode("\n", trim(Process::run(['mksquashfs', '-version'])[1]))[0];

        if (!str_contains((string) end($said), $hostVersion)) {
            self::markTestIncomplete("The image's mksquashfs is not the host's ({$hostVersion}), so the bytes may differ.");
        }

        $listing = Process::mustRun(['unsquashfs', '-o', (string) $offset, '-ll', $docker]);
        self::assertMatchesRegularExpression('#^-rwxr-xr-x root/\S+ .* squashfs-root/AppRun$#m', $listing);
        self::assertSame(hash_file('sha256', $this->build($runtime, $this->dir . '/host.AppImage')), hash_file('sha256', $docker));
    }

    /** @return array<string, array{bool}> */
    public static function routes(): array
    {
        return ['a tar on stdin (4.6+)' => [true], 'a staged directory (4.4, 4.5)' => [false]];
    }

    #[DataProvider('routes')]
    public function testTheLayoutAndTheContainer(bool $tar): void
    {
        $this->needTools();

        if ($tar && !Squashfs::host()->tar) {
            self::markTestSkipped('This mksquashfs is older than 4.6 and reads no tar.');
        }

        $runtime = $this->dir . '/runtime';
        Filesystem::write($runtime, ElfBuilder::runtime());
        $out = $this->build($runtime, $this->dir . '/dist/test.AppImage', Squashfs::host($tar));
        $bytes = Filesystem::read($out);

        // An ELF runtime with the AppImage type-2 marker, then the squashfs at the runtime's end.
        self::assertSame("\x7fELF", substr($bytes, 0, 4));
        self::assertSame("AI\x02", substr($bytes, 8, 3));
        $offset = \strlen(ElfBuilder::runtime());
        self::assertSame('hsqs', substr($bytes, $offset, 4), 'squashfs magic right after the runtime');
        self::assertTrue(is_executable($out));
        self::assertSame(0o755, fileperms($out) & 0o777);
        self::assertSame('gh-releases-zsync|fixture|test-app|latest|test-app-*-linux-arm64.AppImage.zsync', AppImage::readUpdateInformation($bytes));
        self::assertSame(hash_file('sha256', $out) . "  test.AppImage\n", Filesystem::read($out . '.sha256'));
        self::assertFileDoesNotExist($out . '.part');

        $list = Process::mustRun(['unsquashfs', '-o', (string) $offset, '-ll', $out]);
        $files = [];

        foreach (explode("\n", $list) as $line) {
            if (preg_match('/^(\S+)\s+(\S+)\s+\d+ \S+ \S+ squashfs-root\/(\S+)(?: -> (\S+))?$/', $line, $m) === 1) {
                $files[$m[3]] = [$m[1], $m[2], $m[4] ?? null];
            }
        }

        $expect = [
            'AppRun'                                              => '-rwxr-xr-x',
            'grafida-shell'                                       => '-rwxr-xr-x',
            'test-worker'                                         => '-rwxr-xr-x',
            'test-worker.phar'                                    => '-rw-r--r--',
            'shell.json'                                          => '-rw-r--r--',
            'LICENSE.txt'                                         => '-rw-r--r--',
            'test.packager.app.desktop'                           => '-rw-r--r--',
            'test.packager.app.png'                               => '-rw-r--r--',
            'usr/share/icons/hicolor/16x16/apps/test.packager.app.png' => '-rw-r--r--',
            'usr/share/icons/hicolor/48x48/apps/test.packager.app.png' => '-rw-r--r--',
        ];

        foreach ($expect as $name => $mode) {
            self::assertArrayHasKey($name, $files, "{$name} is in the image:\n{$list}");
            self::assertSame($mode, $files[$name][0], $name);
            self::assertStringStartsWith('root/', $files[$name][1], "{$name} is owned by root, not by whoever built it");
        }

        self::assertSame('test.packager.app.png', $files['.DirIcon'][2] ?? null);
        self::assertArrayNotHasKey('install.sh', $files);

        // Contents: the launcher is AppRun, and the desktop entry carries the forced keys.
        $root = $this->dir . '/x';
        Process::mustRun(['unsquashfs', '-o', (string) $offset, '-d', $root, '-no-xattrs', $out]);
        self::assertSame('the launcher', Filesystem::read("{$root}/AppRun"));
        self::assertSame('the shell', Filesystem::read("{$root}/grafida-shell"));
        self::assertSame('the sfx', Filesystem::read("{$root}/test-worker"));
        $desktop = Filesystem::read("{$root}/test.packager.app.desktop");
        self::assertStringContainsString("Icon=test.packager.app\n", $desktop);
        self::assertStringContainsString("StartupWMClass=test.packager.app\n", $desktop);
        self::assertStringContainsString("Exec=test-app\n", $desktop);
        self::assertSame(Filesystem::read(Fixture::APP . '/icons/48.png'), Filesystem::read("{$root}/test.packager.app.png"), 'the largest icon sits at the root');
    }

    public function testTheImageIsReproducibleAndTheRuntimeKeptWithoutUpdateInformation(): void
    {
        $this->needTools();
        $runtime = $this->dir . '/runtime';
        Filesystem::write($runtime, ElfBuilder::runtime());
        $a = $this->build($runtime, $this->dir . '/a.AppImage');
        sleep(1);
        // Different owner-visible state: a touched source file must not matter.
        touch("{$this->dir}/launcher", time() + 5);
        $b = $this->build($runtime, $this->dir . '/b.AppImage');
        self::assertSame(hash_file('sha256', $a), hash_file('sha256', $b));

        $config = Fixture::config(linux: ['appimage' => []]);
        AppImage::write($config, $this->binaries(), $runtime, "{$this->dir}/payload", $this->dir . '/c.AppImage', 1700000000, Arch::Aarch64, Squashfs::host());
        self::assertNull(AppImage::readUpdateInformation(Filesystem::read($this->dir . '/c.AppImage')));
    }

    public function testARuntimeThatIsNotTypeTwoIsRefused(): void
    {
        $this->needTools();
        Filesystem::write($this->dir . '/runtime', substr_replace(ElfBuilder::runtime(), 'XXX', 8, 3));
        $this->expectExceptionMessage('not an AppImage type-2 runtime');
        $this->build($this->dir . '/runtime', $this->dir . '/bad.AppImage');
    }

    public function testTheRealPinnedRuntime(): void
    {
        $this->needTools();
        $runtime = getenv('SHELL_PACKAGER_TEST_APPIMAGE_RUNTIME');

        if (!\is_string($runtime) || !is_file($runtime)) {
            self::markTestSkipped('SHELL_PACKAGER_TEST_APPIMAGE_RUNTIME names the pinned type-2 runtime (fetch --platform=linux).');
        }

        $out    = $this->build($runtime, $this->dir . '/real.AppImage');
        $bytes  = Filesystem::read($out);
        $offset = (int) filesize($runtime);

        self::assertSame("AI\x02", substr($bytes, 8, 3));
        self::assertSame('hsqs', substr($bytes, $offset, 4));
        self::assertSame(substr(Filesystem::read($runtime), 0, 8), substr($bytes, 0, 8));
        self::assertStringContainsString('AppRun', Process::mustRun(['unsquashfs', '-o', (string) $offset, '-l', $out]));
        self::assertNotNull(AppImage::readUpdateInformation($bytes));
    }

    /** @return array{launcher: string, shell: string, sfx: string} */
    private function binaries(): array
    {
        return ['launcher' => "{$this->dir}/launcher", 'shell' => "{$this->dir}/shell", 'sfx' => "{$this->dir}/sfx"];
    }

    private function build(string $runtime, string $out, ?Squashfs $squashfs = null): string
    {
        $config = Fixture::config();
        AppImage::write($config, $this->binaries(), $runtime, "{$this->dir}/payload", $out, 1700000000, Arch::Aarch64, $squashfs ?? Squashfs::host());

        return $out;
    }

    private function needTools(): void
    {
        if (!Process::exists('mksquashfs') || !Process::exists('unsquashfs')) {
            self::markTestSkipped('mksquashfs and unsquashfs (squashfs-tools) are needed.');
        }
    }
}
