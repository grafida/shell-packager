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
use Grafida\ShellPackager\Linux\LinuxTarball;
use Grafida\ShellPackager\PackagerException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testNamesComeFromTheManifest(): void
    {
        $config = Fixture::config();

        self::assertSame('test.packager.app', $config->appId);
        self::assertSame('Packager Test', $config->title);
        self::assertSame('test-worker', $config->workerBinary);
        self::assertSame('bin/worker.php', $config->entrypoint);
        self::assertSame([16, 48], array_keys($config->icons));
        self::assertSame(['memory_limit' => '321M', 'opcache.enable' => false, 'display_errors' => 'stderr'], $config->ini);
        self::assertSame(
            realpath(Fixture::APP) . '/dist/test-app-1.2.3-linux-arm64.tar.gz',
            LinuxTarball::outputPath($config, Arch::Aarch64, '1.2.3')
        );
    }

    public function testAMissingRuntimeIsNamed(): void
    {
        $this->expectExceptionMessage("pins no linux 'shell' runtime (runtimes.linux.shell)");
        Fixture::config()->runtime('shell');
    }

    public function testAMissingFileIsNamed(): void
    {
        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('No packaging configuration');
        Config::load('/nonexistent/shell-package.json');
    }

    public function testTheDesktopEntryIsMadeToAgreeWithThePackage(): void
    {
        $config = Fixture::config();
        $entry  = LinuxTarball::desktopEntry((string) file_get_contents(Fixture::APP . '/app.desktop'), $config);

        self::assertSame(
            "[Desktop Entry]\nType=Application\nName=Packager Test\nComment=A fixture\nExec=test-app\nIcon=test.packager.app\n"
            . "Categories=Utility;\nStartupWMClass=test.packager.app\n\n[Desktop Action New]\nName=New\nExec=whatever --new\n",
            $entry
        );
    }

    public function testTheInstallerIsFilledIn(): void
    {
        $script = LinuxTarball::installer(Fixture::config());

        self::assertStringContainsString("APP_ID='test.packager.app'\nNAME='test-app'\nTITLE='Packager Test'\nREPLACES=''\n", $script);
        self::assertStringNotContainsString('@', preg_replace('/^#.*$/m', '', $script) ?? '');

        $script = LinuxTarball::installer(Fixture::config(linux: ['replaces' => ['old-app', 'org.example.Old', 'old-app']]));
        self::assertStringContainsString("REPLACES='old-app org.example.Old'\n", $script);
    }

    public function testReplacesListsDesktopIdsOnly(): void
    {
        foreach ([['a b'], ['../x'], ['*'], [''], 'old-app', ['k' => 'old-app'], ['test.packager.app']] as $replaces) {
            try {
                Fixture::config(linux: ['replaces' => $replaces]);
                self::fail('accepted ' . json_encode($replaces));
            } catch (PackagerException $e) {
                self::assertStringContainsString('linux.replaces', $e->getMessage());
            }
        }
    }
}
