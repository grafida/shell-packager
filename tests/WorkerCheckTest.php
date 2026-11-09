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
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\TestCase;

/**
 * Against a real Linux phpmicro sibling-phar SFX, named by SHELL_PACKAGER_TEST_SFX (CI downloads
 * the pinned one); skipped without it.
 */
#[RequiresSetting('phar.readonly', '0')]
final class WorkerCheckTest extends TestCase
{
    private string $sfx;

    protected function setUp(): void
    {
        $sfx = getenv('SHELL_PACKAGER_TEST_SFX');
        $arch = Arch::host();

        if (!\is_string($sfx) || !is_file($sfx) || $arch === null || !WorkerCheck::canRun($arch)) {
            self::markTestSkipped('Set SHELL_PACKAGER_TEST_SFX to a Linux sibling-phar micro.sfx for this machine.');
        }

        $this->sfx = $sfx;
    }

    public function testTheIniBlockAppliesAndTheWorkerSendsReady(): void
    {
        $config = Fixture::config();
        $dir    = Filesystem::tempDir('worker-check-test');

        try {
            (new PharBuilder())->build($config, $dir . '/test-worker.phar', 1700000000);
            WorkerCheck::iniProbe($this->sfx, $config->ini);
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
        WorkerCheck::iniProbe($this->sfx, ['shell_packager.no_such_setting' => 'x']);
    }
}
