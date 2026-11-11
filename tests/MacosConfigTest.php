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
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\Macos\InfoPlist;
use Grafida\ShellPackager\Macos\MacApp;
use Grafida\ShellPackager\Macos\MacDmg;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Platform;
use Grafida\ShellPackager\Process;
use PHPUnit\Framework\TestCase;

/** The `macos` section, platform-grouped `runtimes`, and Info.plist generation. */
final class MacosConfigTest extends TestCase
{
    /** @param array<string, mixed> $changes merged over the fixture's configuration */
    private static function load(array $changes): Config
    {
        $data = json_decode(Filesystem::read(Fixture::APP . '/shell-package.json'), true);
        \assert(\is_array($data));
        $path = Fixture::APP . '/shell-package.test-' . bin2hex(random_bytes(4)) . '.json';
        Filesystem::write($path, (string) json_encode(array_replace($data, $changes)));

        try {
            return Config::load($path);
        } finally {
            unlink($path);
        }
    }

    public function testRuntimesAreGroupedByPlatform(): void
    {
        $config = self::load([]);

        self::assertStringEndsWith('/grafida-shell-macos-arm64', $config->runtime('shell', Platform::Macos)->url(Arch::Aarch64));
        self::assertStringEndsWith('/grafida-shell-linux-x86_64', $config->runtime('shell')->url(Arch::X86_64));
        self::assertSame('linux', Platform::Linux->value);
    }

    public function testAFlatRuntimesIsLinux(): void
    {
        $pin    = ['url' => 'https://example.invalid/x-{arch}', 'sha256' => ['x86_64' => str_repeat('a', 64)]];
        $config = self::load(['runtimes' => ['shell' => $pin]]);

        self::assertSame('https://example.invalid/x-{arch}', $config->runtime('shell', Platform::Linux)->url);
        $this->expectExceptionMessage("pins no macos 'shell' runtime");
        $config->runtime('shell', Platform::Macos);
    }

    public function testMixedRuntimeShapesAreRefused(): void
    {
        $pin = ['url' => 'https://example.invalid/x', 'sha256' => ['aarch64' => str_repeat('a', 64)]];
        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('mixes platform groups (macos) with ungrouped pins (shell)');
        self::load(['runtimes' => ['shell' => $pin, 'macos' => ['shell' => $pin]]]);
    }

    public function testTheMacosSectionHasDefaults(): void
    {
        $macos = self::load(['macos' => []])->macos();

        self::assertSame('Packager Test', $macos->name);
        self::assertNull($macos->icon);
        self::assertSame(['shell' => null, 'worker' => null], $macos->entitlements);
        self::assertSame([640, 400], $macos->window);
        self::assertSame([160, 210], $macos->appPosition);
        self::assertSame([480, 210], $macos->applicationsPosition);
        self::assertSame('Packager Test', $macos->volumeName);
        self::assertStringEndsWith('/build/dist/Packager Test-2.1-macos-arm64.dmg', MacDmg::outputPath(self::load(['macos' => []]), '2.1'));
        self::assertStringEndsWith('/build/shell-macos/Packager Test.app', MacApp::outputPath(self::load(['macos' => []])));
    }

    public function testABadBundleNameIsRefused(): void
    {
        $this->expectExceptionMessage('must be a plain file name');
        self::load(['macos' => ['name' => '../Evil']]);
    }

    public function testAnUnknownEntitlementsTargetIsRefused(): void
    {
        $this->expectExceptionMessage("maps 'shell' and/or 'worker'");
        self::load(['macos' => ['entitlements' => ['launcher' => 'LICENSE.txt']]]);
    }

    public function testInfoPlistComputesWhatMustAgree(): void
    {
        $plist = InfoPlist::build(self::load([]), '1.2.3', true);

        self::assertSame('test.packager.app', $plist['CFBundleIdentifier']);
        self::assertSame('Packager Test', $plist['CFBundleExecutable']);
        self::assertSame('Packager Test', $plist['CFBundleIconFile']);
        self::assertSame('1.2.3', $plist['CFBundleShortVersionString']);
        self::assertSame('1.2.3', $plist['CFBundleVersion']);
        self::assertSame('12.0', $plist['LSMinimumSystemVersion']);
        self::assertSame('public.app-category.developer-tools', $plist['LSApplicationCategoryType']);
        self::assertArrayNotHasKey('CFBundleIconFile', InfoPlist::build(self::load([]), '1.2.3', false));
    }

    public function testAComputedKeyInTheExtrasIsAnError(): void
    {
        $config = self::load(['macos' => ['infoPlist' => ['CFBundleIdentifier' => 'evil.other', 'LSMinimumSystemVersion' => '11.0']]]);
        $this->expectExceptionMessage('macos.infoPlist sets CFBundleIdentifier, LSMinimumSystemVersion, which the packager computes');
        InfoPlist::build($config, '1.0', false);
    }

    public function testAVersionMacosCannotTakeIsRefused(): void
    {
        $this->expectExceptionMessage('one to three dot-separated integers');
        InfoPlist::build(self::load([]), '1.0-beta', false);
    }

    public function testThePlistIsXmlAPlistReaderAgreesWith(): void
    {
        $plist = [
            'Text'   => 'a <b> & "c"',
            'Yes'    => true,
            'No'     => false,
            'Number' => 3,
            'List'   => ['x', 1],
            'Dict'   => ['Inner' => ['Deep' => 'yes']],
            'Empty'  => [],
        ];
        $xml = InfoPlist::xml($plist);
        self::assertStringContainsString('<string>a &lt;b&gt; &amp; &quot;c&quot;</string>', $xml);

        if (!Process::exists('plutil')) {
            self::markTestSkipped('plutil (macOS) reads it back');
        }

        $json = Process::mustRun(['plutil', '-convert', 'json', '-o', '-', '-'], $xml);
        self::assertSame(['Dict' => ['Inner' => ['Deep' => 'yes']], 'Empty' => [], 'List' => ['x', 1], 'No' => false, 'Number' => 3, 'Text' => 'a <b> & "c"', 'Yes' => true], self::sorted(json_decode($json, true)));
    }

    /** @return array<mixed> */
    private static function sorted(mixed $data): array
    {
        \assert(\is_array($data));
        ksort($data);

        return $data;
    }
}
