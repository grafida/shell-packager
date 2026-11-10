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
use Grafida\ShellPackager\Macos\Codesign;
use Grafida\ShellPackager\Macos\MacApp;
use Grafida\ShellPackager\Macos\MacDmg;
use Grafida\ShellPackager\Macos\MachO;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Phar\PharBuilder;
use Grafida\ShellPackager\Phar\WorkerCheck;
use Grafida\ShellPackager\Platform;
use Grafida\ShellPackager\Process;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\TestCase;

/**
 * The bundle: its layout and the checks on what goes in, with synthetic Mach-O files (any host);
 * then, on a Mac with the real pinned runtimes (SHELL_PACKAGER_TEST_MACOS_SHELL and _SFX), signing,
 * the signatures read back, the worker answering from inside the signed bundle, and the image.
 */
#[RequiresSetting('phar.readonly', '0')]
final class MacAppTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Filesystem::tempDir('macapp-test');
    }

    protected function tearDown(): void
    {
        Filesystem::remove($this->dir);
    }

    /** @param array<string, mixed> $macos merged over the fixture's macos section */
    private static function config(array $macos = []): Config
    {
        $data = json_decode(Filesystem::read(Fixture::APP . '/shell-package.json'), true);
        \assert(\is_array($data) && \is_array($data['macos']));
        $data['macos'] = array_replace($data['macos'], $macos);
        $path          = Fixture::APP . '/shell-package.test-' . bin2hex(random_bytes(4)) . '.json';
        Filesystem::write($path, (string) json_encode($data));

        try {
            return Config::load($path);
        } finally {
            unlink($path);
        }
    }

    /** @return array{shell: string, sfx: string} */
    private function synthetic(string $shell = '', string $sfx = ''): array
    {
        $shell = $shell !== '' ? $shell : MachOBuilder::bytes();
        $sfx   = $sfx !== '' ? $sfx : MachOBuilder::bytes(image: 8192);
        Filesystem::write($this->dir . '/shell', $shell);
        Filesystem::write($this->dir . '/sfx', $sfx);

        return ['shell' => $this->dir . '/shell', 'sfx' => $this->dir . '/sfx'];
    }

    public function testTheBundleLayout(): void
    {
        $config = self::config();
        Filesystem::write($this->dir . '/payload', 'PAYLOAD');
        $app = $this->dir . '/Packager Test.app';
        MacApp::assemble($config, $this->synthetic(), $this->dir . '/payload', $app, '1.0.0');

        $c = $app . '/Contents';
        self::assertFileExists("{$c}/MacOS/Packager Test");
        self::assertTrue(is_executable("{$c}/MacOS/Packager Test"));
        self::assertTrue(is_executable("{$c}/MacOS/test-worker"));
        self::assertSame('PAYLOAD', Filesystem::read("{$c}/Resources/test-worker.phar"));
        self::assertSame(Filesystem::read($config->manifestPath), Filesystem::read("{$c}/Resources/shell.json"));
        self::assertFileExists("{$c}/Resources/Packager Test.icns");
        self::assertFileExists("{$c}/Resources/LICENSE.txt");
        self::assertSame('APPL????', Filesystem::read("{$c}/PkgInfo"));
        self::assertStringContainsString('<key>CFBundleExecutable</key>', Filesystem::read("{$c}/Info.plist"));
        // Only code in MacOS: codesign refuses anything else there.
        self::assertSame(['Packager Test', 'test-worker'], array_values(array_diff((array) scandir("{$c}/MacOS"), ['.', '..'])));
    }

    public function testAStubWithItsPayloadStillAppendedIsRefused(): void
    {
        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('bytes past its Mach-O image');
        MacApp::assemble(self::config(), $this->synthetic(sfx: MachOBuilder::bytes(appended: str_repeat('P', 100))), __FILE__, $this->dir . '/A.app', '1.0');
    }

    public function testABinaryAboveTheFloorIsRefused(): void
    {
        $this->expectExceptionMessage('needs macOS 13.0, above the bundle\'s LSMinimumSystemVersion 12.0');
        MacApp::assemble(self::config(), $this->synthetic(shell: MachOBuilder::bytes(minos: '13.0')), __FILE__, $this->dir . '/A.app', '1.0');
    }

    public function testAnIntelBinaryIsRefused(): void
    {
        $this->expectExceptionMessage('not an arm64 binary');
        MacApp::assemble(self::config(), $this->synthetic(sfx: MachOBuilder::bytes(cpu: MachO::CPU_X86_64)), __FILE__, $this->dir . '/A.app', '1.0');
    }

    public function testNamesThatDifferOnlyInCaseAreOneFile(): void
    {
        $this->expectExceptionMessage('would be the same file');
        MacApp::assemble(self::config(['name' => 'TEST-WORKER']), $this->synthetic(), __FILE__, $this->dir . '/A.app', '1.0');
    }

    /** @return array{shell: string, sfx: string} */
    private static function realRuntimes(): array
    {
        $shell = getenv('SHELL_PACKAGER_TEST_MACOS_SHELL');
        $sfx   = getenv('SHELL_PACKAGER_TEST_MACOS_SFX');

        if (!\is_string($shell) || !is_file($shell) || !\is_string($sfx) || !is_file($sfx) || !WorkerCheck::canRun(Arch::Aarch64, Platform::Macos)) {
            self::markTestSkipped('An arm64 Mac with SHELL_PACKAGER_TEST_MACOS_SHELL and _SFX set to the pinned runtimes.');
        }

        return ['shell' => $shell, 'sfx' => $sfx];
    }

    private function signedApp(Config $config): string
    {
        $payload = $this->dir . '/test-worker.phar';
        (new PharBuilder())->build($config, $payload, 1700000000);
        $app = $this->dir . '/Packager Test.app';
        MacApp::assemble($config, self::realRuntimes(), $payload, $app, '1.0.0');
        MacApp::sign($config, $app, new Codesign(null));

        return $app;
    }

    public function testSignedAdHocWithTheHardenedRuntimeAndNoEntitlements(): void
    {
        $app = $this->signedApp(self::config());

        foreach (['Packager Test', 'test-worker'] as $binary) {
            $path = "{$app}/Contents/MacOS/{$binary}";
            self::assertTrue(Codesign::hasHardenedRuntime($path), $binary);
            self::assertSame([], Codesign::entitlements($path), $binary);
        }

        Codesign::verify($app);
        // No entitlement at all, under the hardened runtime: the worker still answers, from its
        // payload in ../Resources. This is the test any entitlement an app adds has to fail first.
        self::assertStringContainsString('"type":"ready"', WorkerCheck::handshakeAt("{$app}/Contents/MacOS/test-worker"));
    }

    public function testConfiguredEntitlementsAreExactlyWhatIsSigned(): void
    {
        $plist = $this->dir . '/worker.entitlements';
        Filesystem::write($plist, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE plist PUBLIC \"-//Apple//DTD PLIST 1.0//EN\" \"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">\n"
            . "<plist version=\"1.0\"><dict><key>com.apple.security.cs.disable-library-validation</key><true/></dict></plist>\n");
        $app = $this->signedApp(self::config(['entitlements' => ['worker' => $plist]]));

        self::assertSame(['com.apple.security.cs.disable-library-validation' => true], Codesign::entitlements("{$app}/Contents/MacOS/test-worker"));
        self::assertSame([], Codesign::entitlements("{$app}/Contents/MacOS/Packager Test"));
    }

    public function testTheImageHoldsTheSignedAppAndTheApplicationsLink(): void
    {
        $config = self::config();
        $app    = $this->signedApp($config);
        $dmg    = $this->dir . '/out/test.dmg';
        MacDmg::build($config, $app, $dmg, new Codesign(null), false, static function (string $line): void {
        });

        self::assertFileExists($dmg . '.sha256');
        Process::mustRun(['hdiutil', 'verify', $dmg]);
        $attach = Process::mustRun(['hdiutil', 'attach', '-readonly', '-noverify', '-noautoopen', '-nobrowse', '-mountrandom', $this->dir, $dmg]);
        // The mount point is the last tab-separated column of the line that has one.
        $mount = '';

        foreach (explode("\n", $attach) as $line) {
            $columns = array_map('trim', explode("\t", $line));
            $last    = $columns[\count($columns) - 1];

            if (\count($columns) > 1 && str_starts_with($last, '/') && !str_starts_with($last, '/dev/')) {
                $mount = $last;
            }
        }

        self::assertNotSame('', $mount, $attach);

        try {
            self::assertSame('/Applications', readlink($mount . '/Applications'));
            self::assertFileExists($mount . '/.VolumeIcon.icns');
            Codesign::verify($mount . '/Packager Test.app');
        } finally {
            Process::run(['hdiutil', 'detach', $mount, '-force']);
        }
    }

    public function testNotarisationWithoutAnIdentityIsRefused(): void
    {
        putenv(MacDmg::NOTARY_ENV . '=some-profile');

        try {
            $this->expectExceptionMessage('notarisation needs a Developer ID signature');
            MacDmg::build(self::config(), $this->dir, $this->dir . '/x.dmg', new Codesign(null), false, static function (string $line): void {
            });
        } finally {
            putenv(MacDmg::NOTARY_ENV);
        }
    }
}
