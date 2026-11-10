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
use Grafida\ShellPackager\Phar\IniBlock;
use Grafida\ShellPackager\Phar\PharBuilder;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\TestCase;

#[RequiresSetting('phar.readonly', '0')]
final class PharBuilderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Filesystem::tempDir('phar-test');
    }

    protected function tearDown(): void
    {
        Filesystem::remove($this->dir);
    }

    public function testThePayloadIsTheIniBlockThenARunnablePhar(): void
    {
        $count   = (new PharBuilder())->build(Fixture::config(), $this->dir . '/test-worker.phar', 1700000000);
        $payload = Filesystem::read($this->dir . '/test-worker.phar');
        $block   = IniBlock::decode($payload);

        self::assertGreaterThan(5, $count);
        self::assertSame("memory_limit=321M\nopcache.enable=0\ndisplay_errors=stderr\n", $block['text']);

        // Without the block it is an ordinary PHAR, runnable by plain php (which then has its own ini).
        Filesystem::write($this->dir . '/bare.phar', substr($payload, $block['length']));
        exec(\PHP_BINARY . ' -d memory_limit=99M ' . escapeshellarg($this->dir . '/bare.phar') . ' --hello 2>&1', $out, $code);

        self::assertSame(0, $code, implode("\n", $out));
        self::assertSame(['hello from the phar 99M runtime-lib no-dev-lib index'], $out);
    }

    public function testDevDependenciesAndExcludedFilesAreNotPacked(): void
    {
        (new PharBuilder())->build(Fixture::config(), $this->dir . '/test-worker.phar', 1700000000);
        $payload = Filesystem::read($this->dir . '/test-worker.phar');
        Filesystem::write($this->dir . '/bare.phar', substr($payload, IniBlock::decode($payload)['length']));

        $names = [];

        foreach (new \RecursiveIteratorIterator(new \Phar($this->dir . '/bare.phar')) as $file) {
            /** @var \PharFileInfo $file */
            // The real path: macOS's temp dir is behind a symlink (/var -> /private/var).
            $names[] = substr($file->getPathname(), \strlen('phar://' . realpath($this->dir) . '/bare.phar/'));
        }

        sort($names);
        self::assertContains('bin/worker.php', $names);
        self::assertContains('src/Greeter.php', $names);
        self::assertContains('assets/img/x.gif', $names);
        self::assertContains('vendor/autoload.php', $names);
        self::assertContains('vendor/test/runtime-lib/src/Lib.php', $names);
        self::assertNotContains('vendor/test/runtime-lib/tests/LibTest.php', $names);
        self::assertSame([], preg_grep('#dev-lib#', $names));
        self::assertSame([], preg_grep('#^(composer\.|shell|app\.desktop|icons/)#', $names));
    }

    public function testOneTreeBuildsOneByteSequence(): void
    {
        $builder = new PharBuilder();
        $builder->build(Fixture::config(), $this->dir . '/a.phar', 1700000000);
        $builder->build(Fixture::config(), $this->dir . '/b.phar', 1700000000);

        self::assertSame(hash_file('sha256', $this->dir . '/a.phar'), hash_file('sha256', $this->dir . '/b.phar'));
    }
}
