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
use Grafida\ShellPackager\Fetcher;
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\Linux\LinuxPackage;
use Grafida\ShellPackager\Linux\Nfpm;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Process;
use PHPUnit\Framework\TestCase;

/**
 * The .deb and .rpm (grafida/grafida#87). The nfpm configuration is checked everywhere; the packages
 * themselves when SHELL_PACKAGER_TEST_NFPM names an nfpm binary (CI fetches the pinned one with
 * `fetch --nfpm`), read back with ar/tar and, for the .rpm, with `rpm` when the host has it.
 */
final class LinuxPackageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Filesystem::tempDir('linux-package-test');

        foreach (['launcher', 'shell', 'sfx', 'payload'] as $name) {
            Filesystem::write("{$this->dir}/{$name}", "the {$name}");
        }
    }

    protected function tearDown(): void
    {
        Filesystem::remove($this->dir);
    }

    /** @return array{launcher: string, shell: string, sfx: string} */
    private function runtimes(): array
    {
        return ['launcher' => "{$this->dir}/launcher", 'shell' => "{$this->dir}/shell", 'sfx' => "{$this->dir}/sfx"];
    }

    /**
     * @param array<string, mixed> $linux
     * @return array{arch: string, depends: list<string>, contents: list<array<string, mixed>>, version: string, maintainer: string, license: string, homepage: string, section?: string}
     */
    private function configuration(string $format, Arch $arch, string $version = '1.2.3', array $linux = []): array
    {
        $json = LinuxPackage::configuration(Fixture::config(linux: $linux), $format, $this->runtimes(), "{$this->dir}/payload", $arch, $version, $this->dir);
        $data = json_decode($json, true);
        \assert(\is_array($data));

        /** @var array{arch: string, depends: list<string>, contents: list<array<string, mixed>>, version: string, maintainer: string, license: string, homepage: string, section?: string} $data */
        return $data;
    }

    public function testTheContentsAreTheTarballsUnderOpt(): void
    {
        $data = $this->configuration('deb', Arch::X86_64);
        $map  = [];

        foreach ($data['contents'] as $item) {
            /** @var array{dst: string, type?: string, src?: string, file_info?: array{mode: int}} $item */
            $map[$item['dst']] = [$item['type'] ?? 'file', $item['file_info']['mode'] ?? null, $item['src'] ?? null];
        }

        self::assertSame(['dir', 0o755, null], $map['/opt/test-app']);
        self::assertSame(['file', 0o755, "{$this->dir}/launcher"], $map['/opt/test-app/test-app']);
        self::assertSame(['file', 0o755, "{$this->dir}/shell"], $map['/opt/test-app/grafida-shell']);
        self::assertSame(['file', 0o755, "{$this->dir}/sfx"], $map['/opt/test-app/test-worker']);
        self::assertSame(['file', 0o644, "{$this->dir}/payload"], $map['/opt/test-app/test-worker.phar']);
        self::assertSame(0o644, $map['/opt/test-app/shell.json'][1]);
        self::assertSame(0o644, $map['/opt/test-app/LICENSE.txt'][1]);
        self::assertSame(['symlink', null, '/opt/test-app/test-app'], $map['/usr/bin/test-app']);
        self::assertSame(0o644, $map['/usr/share/applications/test.packager.app.desktop'][1]);
        self::assertSame(0o644, $map['/usr/share/icons/hicolor/16x16/apps/test.packager.app.png'][1]);
        self::assertSame(0o644, $map['/usr/share/icons/hicolor/48x48/apps/test.packager.app.png'][1]);
        self::assertCount(11, $map);
        self::assertArrayNotHasKey('/opt/test-app/install.sh', $map, 'the package manager does the installer\'s job');

        $desktop = Filesystem::read($this->dir . '/test.packager.app.desktop');
        self::assertStringContainsString("Exec=test-app\n", $desktop, 'Exec is the symlink on PATH');
        self::assertStringContainsString("Icon=test.packager.app\n", $desktop);
    }

    public function testDependenciesAndArchitecturesFollowTheFormat(): void
    {
        $deb = $this->configuration('deb', Arch::Aarch64, '1.2.3', ['package' => [
            'maintainer' => 'A <a@example.invalid>', 'license' => 'MIT', 'depends' => ['deb' => ['xdg-utils'], 'rpm' => ['xdg-utils-rpm']],
        ]]);
        $rpm = $this->configuration('rpm', Arch::X86_64, '1.2.3', ['package' => [
            'maintainer' => 'A <a@example.invalid>', 'license' => 'MIT', 'depends' => ['deb' => ['xdg-utils'], 'rpm' => ['xdg-utils-rpm']],
        ]]);

        self::assertSame(['libwebkit2gtk-4.1-0', 'libgtk-3-0', 'xdg-utils'], $deb['depends']);
        self::assertSame(['webkit2gtk4.1', 'gtk3', 'xdg-utils-rpm'], $rpm['depends']);
        // nfpm takes Go's names and writes x86_64/aarch64 itself into an .rpm.
        self::assertSame('arm64', $deb['arch']);
        self::assertSame('amd64', $rpm['arch']);
        self::assertSame('Packager Test <packager@example.invalid>', $this->configuration('deb', Arch::X86_64)['maintainer']);
        self::assertSame('MIT', $this->configuration('rpm', Arch::X86_64)['license']);
        self::assertSame('https://example.invalid/test-app', $this->configuration('rpm', Arch::X86_64)['homepage']);
        self::assertSame('utils', $deb['section'] ?? null);
        self::assertArrayNotHasKey('section', $rpm);
    }

    public function testTheVersionIsMadeSafeForBothFormats(): void
    {
        self::assertSame('0.5~rc1', LinuxPackage::packageVersion('0.5-rc1'));
        self::assertSame('0.4', LinuxPackage::packageVersion('0.4'));
        self::assertSame('0.5~rc1', $this->configuration('rpm', Arch::X86_64, '0.5-rc1')['version']);

        foreach (['v1.0', 'e2e', '1.0 beta', '1:2'] as $bad) {
            try {
                LinuxPackage::packageVersion($bad);
                self::fail("'{$bad}' was accepted");
            } catch (PackagerException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheScriptsAreWrittenForTheApp(): void
    {
        $script = new \ReflectionMethod(LinuxPackage::class, 'script');
        $post   = $script->invoke(null, 'package-postinst.sh', Fixture::config(linux: ['replaces' => ['old.test.app']]));
        $rm     = $script->invoke(null, 'package-postrm.sh', Fixture::config());

        self::assertIsString($post);
        self::assertIsString($rm);
        self::assertStringContainsString("NAME='test-app'\nREPLACES='old.test.app'\n", $post);
        self::assertStringContainsString("NAME='test-app'\n", $rm);
        self::assertStringNotContainsString('@', $post . $rm);
    }

    public function testOutputPaths(): void
    {
        $config = Fixture::config();
        $root   = (string) realpath(Fixture::APP);

        self::assertSame("{$root}/build/dist/test-app-1.2.3-linux-arm64.deb", LinuxPackage::outputPath($config, 'deb', Arch::Aarch64, '1.2.3'));
        self::assertSame("{$root}/build/dist/test-app-1.2.3-linux-x86_64.rpm", LinuxPackage::outputPath($config, 'rpm', Arch::X86_64, '1.2.3'));
    }

    public function testThePackageSectionIsRequiredAndChecked(): void
    {
        $bare = Fixture::config(linux: ['package' => null]);
        self::assertNull($bare->linuxPackage);

        try {
            $bare->linuxPackage();
            self::fail('a missing linux.package was accepted');
        } catch (PackagerException $e) {
            self::assertStringContainsString('linux.package', $e->getMessage());
        }

        foreach ([['license' => 'MIT'], ['maintainer' => 'A'], ['maintainer' => 'A', 'license' => 'MIT', 'depends' => ['rpm' => 'x']], ['maintainer' => 'A', 'license' => 'MIT', 'output' => ['snap' => 'x']]] as $bad) {
            try {
                Fixture::config(linux: ['package' => $bad]);
                self::fail('accepted ' . json_encode($bad));
            } catch (PackagerException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testADollarSignIsRefusedBecauseNfpmExpandsIt(): void
    {
        $this->expectExceptionMessage('"$"');
        $this->configuration('deb', Arch::X86_64, '1.2.3', ['package' => ['maintainer' => 'A', 'license' => 'MIT', 'description' => 'costs $HOME']]);
    }

    public function testANameThatIsNotAPackageNameIsRefused(): void
    {
        $this->expectExceptionMessage('is not a package name');
        $this->configuration('deb', Arch::X86_64, '1.2.3', ['name' => 'Test App']);
    }

    public function testNfpmIsPinnedPerBuildHost(): void
    {
        foreach (['Darwin', 'Linux'] as $os) {
            $runtime = Nfpm::runtime($os);

            foreach ([Arch::X86_64, Arch::Aarch64] as $arch) {
                self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $runtime->pin($arch));
                self::assertStringStartsWith('https://github.com/goreleaser/nfpm/releases/download/v' . Nfpm::VERSION . '/nfpm_' . Nfpm::VERSION . "_{$os}_", $runtime->url($arch));
                self::assertStringEndsWith('.tar.gz', $runtime->url($arch));
            }
        }

        $this->expectExceptionMessage('macOS and Linux build hosts only');
        Nfpm::runtime('Windows');
    }

    public function testALocalNfpmIsUsedAsIs(): void
    {
        Filesystem::write("{$this->dir}/my-nfpm", 'x');
        $fetcher = new Fetcher($this->dir . '/cache');
        $fetcher->useLocal('nfpm', "{$this->dir}/my-nfpm");

        if (Arch::host() === null || !\in_array(\PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
            self::markTestSkipped('Not a macOS or Linux x86_64/aarch64 host.');
        }

        self::assertSame("{$this->dir}/my-nfpm", Nfpm::binary($fetcher, $this->dir));
    }

    /** @return array{string} the nfpm binary */
    private function needNfpm(): array
    {
        $nfpm = getenv('SHELL_PACKAGER_TEST_NFPM');

        if (!\is_string($nfpm) || $nfpm === '' || !is_file($nfpm)) {
            self::markTestSkipped('SHELL_PACKAGER_TEST_NFPM does not name an nfpm binary.');
        }

        return [$nfpm];
    }

    /** Builds the fixture package and returns its path. */
    private function build(string $format, Arch $arch, string $nfpm, string $version = '1.2.3-rc1'): string
    {
        $out = "{$this->dir}/dist/test-{$arch->value}.{$format}";
        LinuxPackage::write(Fixture::config(), $format, $this->runtimes(), "{$this->dir}/payload", $out, 1700000000, $arch, $version, $nfpm, $this->dir . '/work');

        self::assertSame(hash_file('sha256', $out) . '  ' . basename($out) . "\n", Filesystem::read($out . '.sha256'));

        return $out;
    }

    /** @return array<string, string> */
    private function controlFields(string $control): array
    {
        $fields = [];

        foreach (explode("\n", trim($control)) as $line) {
            if (preg_match('/^([A-Za-z-]+): (.*)$/', $line, $m) === 1) {
                $fields[$m[1]] = $m[2];
            }
        }

        return $fields;
    }

    public function testTheDebRead(): void
    {
        [$nfpm] = $this->needNfpm();

        foreach (['x86_64' => 'amd64', 'aarch64' => 'arm64'] as $archName => $debArch) {
            $arch = Arch::from($archName);
            $deb  = $this->build('deb', $arch, $nfpm);
            $x    = $this->dir . '/x-' . $archName;
            Filesystem::mkdir($x);
            Process::mustRun(['sh', '-c', 'cd "$0" && ar x "$1"', $x, $deb]);

            $members = array_values(array_diff((array) scandir($x), ['.', '..']));
            self::assertContains('debian-binary', $members);
            self::assertSame("2.0\n", Filesystem::read("{$x}/debian-binary"));

            $control = (string) current(array_filter($members, static fn (mixed $m): bool => \is_string($m) && str_starts_with($m, 'control.tar')));
            $data    = (string) current(array_filter($members, static fn (mixed $m): bool => \is_string($m) && str_starts_with($m, 'data.tar')));
            $fields  = $this->controlFields(Process::mustRun(['tar', '-xOf', "{$x}/{$control}", './control']));

            self::assertSame('test-app', $fields['Package']);
            self::assertSame('1.2.3~rc1', $fields['Version']);
            self::assertSame($debArch, $fields['Architecture']);
            self::assertSame('Packager Test <packager@example.invalid>', $fields['Maintainer']);
            self::assertSame('libwebkit2gtk-4.1-0, libgtk-3-0', $fields['Depends']);
            self::assertSame('https://example.invalid/test-app', $fields['Homepage']);

            $ctl = $x . '/ctl';
            Filesystem::mkdir($ctl);
            Process::mustRun(['tar', '-xpf', "{$x}/{$control}", '-C', $ctl]);

            foreach (['postinst', 'postrm'] as $script) {
                self::assertSame(0o755, fileperms("{$ctl}/{$script}") & 0o777, $script);
            }

            $root = $x . '/root';
            Filesystem::mkdir($root);
            Process::mustRun(['tar', '-xpf', "{$x}/{$data}", '-C', $root]);

            self::assertSame(0o755, fileperms("{$root}/opt/test-app/test-app") & 0o777);
            self::assertSame(0o755, fileperms("{$root}/opt/test-app/test-worker") & 0o777);
            self::assertSame(0o755, fileperms("{$root}/opt/test-app/grafida-shell") & 0o777);
            self::assertSame(0o644, fileperms("{$root}/opt/test-app/test-worker.phar") & 0o777);
            self::assertMatchesRegularExpression(
                '# \./usr/bin/test-app -> /opt/test-app/test-app$#m',
                Process::mustRun(['tar', '-tvf', "{$x}/{$data}"]),
                'an absolute symlink to the launcher'
            );
            self::assertSame(0o755, fileperms("{$root}/usr/bin") & 0o777);
            self::assertSame(0o644, fileperms("{$root}/usr/share/applications/test.packager.app.desktop") & 0o777);
            self::assertSame(0o644, fileperms("{$root}/usr/share/icons/hicolor/48x48/apps/test.packager.app.png") & 0o777);
            self::assertSame('the launcher', Filesystem::read("{$root}/opt/test-app/test-app"));
        }
    }

    public function testTheRpmRead(): void
    {
        [$nfpm] = $this->needNfpm();

        foreach ([Arch::X86_64, Arch::Aarch64] as $arch) {
            $rpm = $this->build('rpm', $arch, $nfpm);
            self::assertSame("\xed\xab\xee\xdb", substr(Filesystem::read($rpm), 0, 4), 'the RPM lead magic');

            if (!Process::exists('rpm')) {
                continue;
            }

            $info = Process::mustRun(['rpm', '-qpi', '--nosignature', $rpm]);
            self::assertMatchesRegularExpression('/^Architecture\s*: ' . $arch->value . '$/m', $info);
            self::assertMatchesRegularExpression('/^Version\s*: 1\.2\.3~rc1$/m', $info);
            self::assertMatchesRegularExpression('/^License\s*: MIT$/m', $info);
            self::assertMatchesRegularExpression('/^Packager\s*: Packager Test <packager@example.invalid>$/m', $info);

            $requires = Process::mustRun(['rpm', '-qpR', '--nosignature', $rpm]);
            self::assertStringContainsString("webkit2gtk4.1\n", $requires);
            self::assertStringContainsString("gtk3\n", $requires);

            $list = Process::mustRun(['rpm', '-qplv', '--nosignature', $rpm]);
            self::assertMatchesRegularExpression('/^-rwxr-xr-x.* \/opt\/test-app\/test-app$/m', $list);
            self::assertMatchesRegularExpression('/^-rw-r--r--.* \/opt\/test-app\/test-worker\.phar$/m', $list);
            self::assertMatchesRegularExpression('/^l[-rwx]{9}.* \/usr\/bin\/test-app -> \/opt\/test-app\/test-app$/m', $list);
            self::assertMatchesRegularExpression('/^-rw-r--r--.* \/usr\/share\/applications\/test\.packager\.app\.desktop$/m', $list);
            self::assertMatchesRegularExpression('/^-rw-r--r--.* \/usr\/share\/icons\/hicolor\/16x16\/apps\/test\.packager\.app\.png$/m', $list);

            $scripts = Process::mustRun(['rpm', '-qp', '--scripts', '--nosignature', $rpm]);
            self::assertStringContainsString('postinstall scriptlet', $scripts);
            self::assertStringContainsString('postuninstall scriptlet', $scripts);
        }
    }

    public function testTheSameInputsGiveTheSameBytes(): void
    {
        [$nfpm] = $this->needNfpm();
        $a = $this->build('deb', Arch::X86_64, $nfpm);
        $first = hash_file('sha256', $a);
        $b = $this->build('deb', Arch::X86_64, $nfpm);
        self::assertSame($first, hash_file('sha256', $b));
    }
}
