<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\Archive\TarWriter;
use Grafida\ShellPackager\Filesystem;
use PHPUnit\Framework\TestCase;

final class TarWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Filesystem::tempDir('tar-test');
    }

    protected function tearDown(): void
    {
        Filesystem::remove($this->dir);
    }

    private function write(string $path): string
    {
        Filesystem::write($this->dir . '/big.bin', random_bytes(1) . str_repeat('x', 1048576 * 2 + 3));
        $long = 'app/' . str_repeat('deep/', 25) . 'file.txt';
        $tar  = new TarWriter($path, 1700000000);
        $tar->directory('app');
        $tar->data('app/run', "#!/bin/sh\n", 0o755);
        $tar->file('app/big.bin', $this->dir . '/big.bin');
        $tar->data($long, 'long');
        $tar->close();

        return $long;
    }

    public function testEntriesModesTimesAndLongNames(): void
    {
        $long    = $this->write($this->dir . '/a.tar.gz');
        $entries = TarReader::read($this->dir . '/a.tar.gz');

        self::assertSame(['app/', 'app/run', 'app/big.bin', $long], array_keys($entries));
        self::assertSame('5', $entries['app/']['type']);
        self::assertSame(0o755, $entries['app/run']['mode']);
        self::assertSame(0o644, $entries['app/big.bin']['mode']);
        self::assertSame("#!/bin/sh\n", $entries['app/run']['data']);
        self::assertSame(Filesystem::read($this->dir . '/big.bin'), $entries['app/big.bin']['data']);
        self::assertSame('long', $entries[$long]['data']);

        foreach ($entries as $entry) {
            self::assertSame(1700000000, $entry['mtime']);
            self::assertSame(0, $entry['uid']);
        }
    }

    public function testTheSystemTarReadsIt(): void
    {
        $long = $this->write($this->dir . '/a.tar.gz');
        exec('tar -tzf ' . escapeshellarg($this->dir . '/a.tar.gz') . ' 2>&1', $lines, $code);

        if ($code === 127) {
            self::markTestSkipped('no tar');
        }

        self::assertSame(0, $code, implode("\n", $lines));
        self::assertSame(['app/', 'app/run', 'app/big.bin', $long], $lines);
    }

    public function testTheSameInputGivesTheSameBytes(): void
    {
        $this->write($this->dir . '/a.tar.gz');
        $big = Filesystem::read($this->dir . '/big.bin');
        $tar = new TarWriter($this->dir . '/b.tar.gz', 1700000000);
        $tar->directory('app');
        $tar->data('app/run', "#!/bin/sh\n", 0o755);
        Filesystem::write($this->dir . '/big2.bin', $big);
        $tar->file('app/big.bin', $this->dir . '/big2.bin');
        $tar->data('app/' . str_repeat('deep/', 25) . 'file.txt', 'long');
        $tar->close();

        self::assertSame(hash_file('sha256', $this->dir . '/a.tar.gz'), hash_file('sha256', $this->dir . '/b.tar.gz'));
    }
}
