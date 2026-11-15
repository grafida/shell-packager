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
use Grafida\ShellPackager\Linux\Pkgbuild;
use Grafida\ShellPackager\Linux\PkgbuildConfig;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Process;
use PHPUnit\Framework\TestCase;

/** grafida/grafida#87. The generated file is also syntax-checked with bash and, in CI, makepkg/namcap (see the README). */
final class PkgbuildTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Filesystem::tempDir('pkgbuild-test');
        Filesystem::write("{$this->dir}/x86_64.tar.gz", 'tarball one');
        Filesystem::write("{$this->dir}/aarch64.tar.gz", 'tarball two');
    }

    protected function tearDown(): void
    {
        Filesystem::remove($this->dir);
    }

    public function testTheGeneratedFile(): void
    {
        $text = Pkgbuild::render(Fixture::config(), '1.2.3', ['x86_64' => "{$this->dir}/x86_64.tar.gz", 'aarch64' => "{$this->dir}/aarch64.tar.gz"]);

        self::assertStringContainsString("# Maintainer: Fixture Maintainer <fixture@example.invalid>\n", $text);
        self::assertStringContainsString("pkgname='test-app'\npkgver='1.2.3'\npkgrel=1\n", $text);
        self::assertStringContainsString("pkgdesc='A fixture'\\''s '\\''test'\\'' app'\n", $text);
        self::assertStringContainsString("arch=('x86_64' 'aarch64')\n", $text);
        self::assertStringContainsString("license=('MIT')\n", $text);
        self::assertStringContainsString("depends=('webkit2gtk-4.1' 'gtk3' 'hicolor-icon-theme' 'dbus' 'libsecret')\n", $text);
        self::assertStringContainsString("optdepends=('wl-clipboard: paste on Wayland')\n", $text);
        self::assertStringContainsString("options=('!strip' '!debug' '!emptydirs')\n", $text);
        self::assertStringContainsString("source_x86_64=('test-app-1.2.3-x86_64.tar.gz::https://example.invalid/dl/test-app-1.2.3-linux-amd64.tar.gz')\n", $text);
        self::assertStringContainsString("source_aarch64=('test-app-1.2.3-aarch64.tar.gz::https://example.invalid/dl/test-app-1.2.3-linux-arm64.tar.gz')\n", $text);
        self::assertStringContainsString("sha256sums_x86_64=('" . hash('sha256', 'tarball one') . "')\n", $text);
        self::assertStringContainsString("sha256sums_aarch64=('" . hash('sha256', 'tarball two') . "')\n", $text);
        self::assertStringContainsString('cd "$srcdir/test-app"', $text);
        self::assertStringContainsString('ln -s "/opt/$pkgname/test-app" "$pkgdir/usr/bin/$pkgname"', $text);
        self::assertStringContainsString('"$pkgdir/usr/share/applications/test.packager.app.desktop"', $text);
        self::assertStringContainsString('icons/hicolor/*/apps/test.packager.app.png', $text);
        self::assertStringEndsWith("}\n", $text);
    }

    public function testASingleArchitectureAndAPrereleaseVersion(): void
    {
        $text = Pkgbuild::render(Fixture::config(), '1.0.0-rc1', ['aarch64' => "{$this->dir}/aarch64.tar.gz"]);

        self::assertStringContainsString("arch=('aarch64')\n", $text);
        self::assertStringContainsString("pkgver='1.0.0_rc1'\n", $text, 'pacman versions have no hyphen');
        self::assertStringContainsString('test-app-1.0.0_rc1-aarch64.tar.gz::https://example.invalid/dl/test-app-1.0.0-rc1-linux-arm64.tar.gz', $text, 'the URL keeps the real version');
        self::assertStringNotContainsString('x86_64', $text);
    }

    public function testTheFileIsValidBash(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !Process::exists('bash')) {
            // On Windows `bash` is the WSL launcher, which has no distribution on the runners.
            self::markTestSkipped('no bash');
        }

        $path = $this->dir . '/PKGBUILD';
        Filesystem::write($path, Pkgbuild::render(Fixture::config(), '1.2.3', ['x86_64' => "{$this->dir}/x86_64.tar.gz"]));
        Process::mustRun(['bash', '-n', $path]);
        // Sourced, the quoting must give back the plain values.
        $out = Process::mustRun(['bash', '-c', 'source "$0"; printf "%s|%s|%s" "$pkgdesc" "${depends[*]}" "${sha256sums_x86_64[0]}"', $path]);
        self::assertSame("A fixture's 'test' app|webkit2gtk-4.1 gtk3 hicolor-icon-theme dbus libsecret|" . hash('sha256', 'tarball one'), $out);
    }

    public function testAMissingTarballOrConfigurationIsRefused(): void
    {
        try {
            Pkgbuild::render(Fixture::config(), '1.2.3', ['x86_64' => "{$this->dir}/nope.tar.gz"]);
            self::fail('built from nothing');
        } catch (PackagerException $e) {
            self::assertStringContainsString('has not been built', $e->getMessage());
        }

        $this->expectExceptionMessage('no linux.pkgbuild section');
        Pkgbuild::render(Fixture::config(linux: ['pkgbuild' => null]), '1.2.3', ['x86_64' => "{$this->dir}/x86_64.tar.gz"]);
    }

    public function testTheConfigurationIsValidated(): void
    {
        $good = ['source' => 'https://e.invalid/{name}.tar.gz', 'description' => 'd', 'url' => 'https://e.invalid', 'license' => ['MIT']];
        self::assertSame(PkgbuildConfig::DEFAULT_DEPENDS, [...PkgbuildConfig::DEFAULT_DEPENDS, ...PkgbuildConfig::fromArray($good)->depends]);

        foreach ([
            ['source' => 'http://e.invalid/x'],
            ['source' => 'ftp://e.invalid/x'],
            ['license' => []],
            ['description' => "two\nlines"],
            ['depends' => ['a b']],
            ['depends' => ["x'; rm -rf ~; '"]],
            ['pkgrel' => 0],
        ] as $override) {
            try {
                PkgbuildConfig::fromArray(array_replace($good, $override));
                self::fail('accepted ' . json_encode($override));
            } catch (PackagerException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
