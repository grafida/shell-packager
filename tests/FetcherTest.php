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
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Runtime;
use PHPUnit\Framework\TestCase;

final class FetcherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Filesystem::tempDir('fetcher-test');
        Filesystem::write($this->dir . '/src/tool-x86_64', 'the x86_64 tool');
    }

    protected function tearDown(): void
    {
        Filesystem::remove($this->dir);
    }

    private function runtime(string $pin): Runtime
    {
        // file:///C:/… on Windows: file://C:\… has no path parse_url() can read.
        $dir = \PHP_OS_FAMILY === 'Windows' ? '/' . str_replace('\\', '/', $this->dir) : $this->dir;

        return Runtime::fromArray('tool', ['url' => 'file://' . $dir . '/src/tool-{arch}', 'sha256' => ['amd64' => $pin]]);
    }

    public function testAPinnedDownloadIsCachedByItsHash(): void
    {
        $pin     = hash('sha256', 'the x86_64 tool');
        $fetcher = new Fetcher($this->dir . '/cache');
        $path    = $fetcher->fetch($this->runtime($pin), Arch::X86_64);

        self::assertSame($this->dir . "/cache/{$pin}/tool-x86_64", $path);
        self::assertSame('the x86_64 tool', Filesystem::read($path));

        // The source going away does not matter once it is cached…
        unlink($this->dir . '/src/tool-x86_64');
        self::assertSame($path, $fetcher->fetch($this->runtime($pin), Arch::X86_64));

        // …but a damaged cache entry is noticed, and refetched (here: fails, as the source is gone).
        file_put_contents($path, 'tampered');
        $this->expectException(PackagerException::class);
        $fetcher->fetch($this->runtime($pin), Arch::X86_64);
    }

    public function testAMismatchIsFatalAndLeavesNothingBehind(): void
    {
        $fetcher = new Fetcher($this->dir . '/cache');

        try {
            $fetcher->fetch($this->runtime(str_repeat('0', 64)), Arch::X86_64);
            self::fail('A mismatched download was accepted.');
        } catch (PackagerException $e) {
            self::assertStringContainsString('does not match its pin', $e->getMessage());
            self::assertStringContainsString(hash('sha256', 'the x86_64 tool'), $e->getMessage());
        }

        $left = glob($this->dir . '/cache/*/*');
        self::assertSame([], $left === false ? [] : $left);
    }

    public function testAnArchitectureWithNoPinIsFatal(): void
    {
        $this->expectExceptionMessage('No SHA-256 is pinned for tool on aarch64');
        (new Fetcher($this->dir . '/cache'))->fetch($this->runtime(hash('sha256', 'x')), Arch::Aarch64);
    }

    public function testAMalformedPinIsRefusedWhenTheConfigurationLoads(): void
    {
        $this->expectException(PackagerException::class);
        $this->runtime('ABC');
    }

    public function testALocalOverrideIsUsedAsIs(): void
    {
        $fetcher = new Fetcher($this->dir . '/cache');
        $fetcher->useLocal('tool', $this->dir . '/src/tool-x86_64');

        self::assertSame($this->dir . '/src/tool-x86_64', $fetcher->fetch($this->runtime(str_repeat('0', 64)), Arch::X86_64));
    }
}
