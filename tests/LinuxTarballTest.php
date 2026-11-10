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
use Grafida\ShellPackager\Linux\LinuxTarball;
use PHPUnit\Framework\TestCase;

final class LinuxTarballTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Filesystem::tempDir('linux-test');

        foreach (['launcher', 'shell', 'sfx', 'payload'] as $name) {
            Filesystem::write("{$this->dir}/{$name}", "the {$name}");
        }
    }

    protected function tearDown(): void
    {
        Filesystem::remove($this->dir);
    }

    public function testTheLayout(): void
    {
        $out = $this->dir . '/dist/test.tar.gz';
        LinuxTarball::write(
            Fixture::config(),
            ['launcher' => "{$this->dir}/launcher", 'shell' => "{$this->dir}/shell", 'sfx' => "{$this->dir}/sfx"],
            "{$this->dir}/payload",
            $out,
            1700000000
        );
        $entries = TarReader::read($out);

        $modes = array_map(static fn (array $e): string => \sprintf('%s %o', $e['type'], $e['mode']), $entries);
        self::assertSame([
            'test-app/'                                                 => '5 755',
            'test-app/test-app'                                         => '0 755',
            'test-app/grafida-shell'                                    => '0 755',
            'test-app/test-worker'                                      => '0 755',
            'test-app/test-worker.phar'                                 => '0 644',
            'test-app/shell.json'                                       => '0 644',
            'test-app/test.packager.app.desktop'                        => '0 644',
            'test-app/icons/'                                           => '5 755',
            'test-app/icons/hicolor/'                                   => '5 755',
            'test-app/icons/hicolor/16x16/'                             => '5 755',
            'test-app/icons/hicolor/16x16/apps/'                        => '5 755',
            'test-app/icons/hicolor/16x16/apps/test.packager.app.png'   => '0 644',
            'test-app/icons/hicolor/48x48/'                             => '5 755',
            'test-app/icons/hicolor/48x48/apps/'                        => '5 755',
            'test-app/icons/hicolor/48x48/apps/test.packager.app.png'   => '0 644',
            'test-app/install.sh'                                       => '0 755',
            'test-app/LICENSE.txt'                                      => '0 644',
        ], $modes);

        self::assertSame('the launcher', $entries['test-app/test-app']['data']);
        self::assertSame('the shell', $entries['test-app/grafida-shell']['data']);
        self::assertSame('the sfx', $entries['test-app/test-worker']['data']);
        self::assertSame('the payload', $entries['test-app/test-worker.phar']['data']);
        self::assertStringContainsString('StartupWMClass=test.packager.app', $entries['test-app/test.packager.app.desktop']['data']);
        self::assertSame(hash_file('sha256', $out) . "  test.tar.gz\n", Filesystem::read($out . '.sha256'));
        self::assertFileDoesNotExist($out . '.part');
    }

    public function testTheInstallerInstallsAndUninstalls(): void
    {
        $out = $this->dir . '/test.tar.gz';
        chmod("{$this->dir}/launcher", 0o755);
        LinuxTarball::write(
            Fixture::config(),
            ['launcher' => "{$this->dir}/launcher", 'shell' => "{$this->dir}/shell", 'sfx' => "{$this->dir}/sfx"],
            "{$this->dir}/payload",
            $out,
            1700000000
        );

        // Extracted into a folder whose name needs quoting in a desktop entry.
        $where = $this->dir . '/My Apps';
        Filesystem::mkdir($where);
        exec('tar -xzf ' . escapeshellarg($out) . ' -C ' . escapeshellarg($where) . ' 2>&1', $lines, $code);

        if ($code === 127) {
            self::markTestSkipped('no tar');
        }

        $env = 'XDG_DATA_HOME=' . escapeshellarg($this->dir . '/data') . ' HOME=' . escapeshellarg($this->dir);
        exec("{$env} sh " . escapeshellarg("{$where}/test-app/install.sh") . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));

        $desktop = Filesystem::read($this->dir . '/data/applications/test.packager.app.desktop');
        // install.sh writes the real path (macOS's temp dir is behind /var -> /private/var).
        self::assertStringContainsString('Exec="' . realpath($where) . "/test-app/test-app\"\n", $desktop);
        self::assertStringContainsString("Exec=whatever --new\n", $desktop, 'only the main Exec is rewritten');
        self::assertFileExists($this->dir . '/data/icons/hicolor/48x48/apps/test.packager.app.png');

        exec("{$env} sh " . escapeshellarg("{$where}/test-app/install.sh") . ' --uninstall 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
        self::assertFileDoesNotExist($this->dir . '/data/applications/test.packager.app.desktop');
        self::assertFileDoesNotExist($this->dir . '/data/icons/hicolor/48x48/apps/test.packager.app.png');
    }
}
