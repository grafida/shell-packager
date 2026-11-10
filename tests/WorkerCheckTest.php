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
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Phar\PharBuilder;
use Grafida\ShellPackager\Phar\WorkerCheck;
use Grafida\ShellPackager\Platform;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\TestCase;

/**
 * Against a real phpmicro sibling-phar SFX for this machine: a Linux one named by
 * SHELL_PACKAGER_TEST_SFX, on an arm64 Mac a macOS one named by SHELL_PACKAGER_TEST_MACOS_SFX, or on
 * Windows x86_64 a Windows one named by SHELL_PACKAGER_TEST_WINDOWS_SFX (CI downloads the pinned
 * ones); skipped without it.
 */
#[RequiresSetting('phar.readonly', '0')]
final class WorkerCheckTest extends TestCase
{
    private string $sfx;

    private Platform $platform;

    protected function setUp(): void
    {
        $platform = match (\PHP_OS_FAMILY) {
            'Darwin'  => Platform::Macos,
            'Windows' => Platform::Windows,
            default   => Platform::Linux,
        };
        $env = match ($platform) {
            Platform::Macos   => 'SHELL_PACKAGER_TEST_MACOS_SFX',
            Platform::Windows => 'SHELL_PACKAGER_TEST_WINDOWS_SFX',
            Platform::Linux   => 'SHELL_PACKAGER_TEST_SFX',
        };
        $this->platform = $platform;
        $sfx      = getenv($env);
        $arch     = Arch::host();

        if (!\is_string($sfx) || !is_file($sfx) || $arch === null || !WorkerCheck::canRun($arch, $platform)) {
            self::markTestSkipped("Set {$env} to a {$platform->value} sibling-phar micro.sfx for this machine.");
        }

        $this->sfx = $sfx;
    }

    public function testTheIniBlockAppliesAndTheWorkerSendsReady(): void
    {
        $config = Fixture::config();
        $dir    = Filesystem::tempDir('worker-check-test');

        try {
            (new PharBuilder())->build($config, $dir . '/test-worker.phar', 1700000000);
            WorkerCheck::iniProbe($this->sfx, $config->ini, $this->platform);
            $header = WorkerCheck::handshake($this->sfx, $dir . '/test-worker.phar', 'test-worker');

            self::assertStringContainsString('"type":"ready"', $header);
            self::assertStringContainsString('"app":"fixture"', $header);
        } finally {
            Filesystem::remove($dir);
        }
    }

    public function testAWorkerThatPrintsBeforeReadyIsCaught(): void
    {
        $dir = Filesystem::tempDir('worker-check-test');

        try {
            $phar = new \Phar($dir . '/noisy-src.phar', 0, 'noisy.phar');
            $phar->addFromString('w.php', '<?php echo "Warning: something\n"; sleep(60);');
            $phar->setStub("<?php Phar::mapPhar('noisy.phar'); require 'phar://noisy.phar/w.php'; __HALT_COMPILER();");
            unset($phar);

            $this->expectException(PackagerException::class);
            $this->expectExceptionMessage('stdout began with');
            WorkerCheck::handshake($this->sfx, $dir . '/noisy-src.phar', 'noisy');
        } finally {
            Filesystem::remove($dir);
        }
    }

    public function testAnIgnoredSettingIsCaught(): void
    {
        $this->expectExceptionMessage('did not apply the extra-INI block');
        // A setting phpmicro cannot apply from ini: an unknown name reads back as false.
        WorkerCheck::iniProbe($this->sfx, ['shell_packager.no_such_setting' => 'x'], $this->platform);
    }
}
