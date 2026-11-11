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
use Grafida\ShellPackager\Macos\MachO;
use Grafida\ShellPackager\PackagerException;
use PHPUnit\Framework\TestCase;

final class MachOTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Filesystem::tempDir('macho-test');
    }

    protected function tearDown(): void
    {
        Filesystem::remove($this->dir);
    }

    private function file(string $bytes): string
    {
        $path = $this->dir . '/bin-' . bin2hex(random_bytes(4));
        Filesystem::write($path, $bytes);

        return $path;
    }

    public function testTheMinimumMacosAndTheArchitectureAreRead(): void
    {
        $m = MachO::read($this->file(MachOBuilder::bytes(minos: '12.0')));
        self::assertSame(Arch::Aarch64, $m->arch());
        self::assertSame('12.0', $m->minos);
        self::assertSame(0, $m->appendedBytes());

        self::assertSame('15.5.1', MachO::read($this->file(MachOBuilder::bytes(minos: '15.5.1')))->minos);
        self::assertSame('11.0', MachO::read($this->file(MachOBuilder::bytes(minos: '11.0', legacyVersionCommand: true)))->minos);
        self::assertNull(MachO::read($this->file(MachOBuilder::bytes(minos: null)))->minos);
        self::assertSame(Arch::X86_64, MachO::read($this->file(MachOBuilder::bytes(cpu: MachO::CPU_X86_64)))->arch());
        self::assertNull(MachO::read($this->file(MachOBuilder::bytes(cpu: 0x00000012)))->arch(), 'PowerPC');
    }

    public function testAnAppendedPayloadIsSeen(): void
    {
        // A phpmicro SFX with its PHAR still attached: bytes past the last segment.
        $m = MachO::read($this->file(MachOBuilder::bytes(appended: "\xfd\xf6\x69\xe6" . str_repeat('P', 1000))));
        self::assertSame(1004, $m->appendedBytes());
    }

    public function testAFatBinaryIsRefused(): void
    {
        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('universal (fat)');
        MachO::read($this->file(pack('NN', 0xcafebabe, 2) . str_repeat("\0", 64)));
    }

    public function testSomethingElseIsRefused(): void
    {
        $this->expectExceptionMessage('not a 64-bit Mach-O');
        MachO::read($this->file("#!/bin/sh\necho not a binary\n" . str_repeat(' ', 40)));
    }

    public function testVersionsCompareAsNumbers(): void
    {
        self::assertTrue(MachO::versionAtMost('12.0', '12.0'));
        self::assertTrue(MachO::versionAtMost('11.5', '12'));
        self::assertTrue(MachO::versionAtMost('9.9', '12.0'));
        self::assertFalse(MachO::versionAtMost('12.0.1', '12.0'));
        self::assertFalse(MachO::versionAtMost('13.0', '12.0'));
    }

    public function testTheRealPinnedBinariesCarryNoPayload(): void
    {
        foreach (['SHELL_PACKAGER_TEST_MACOS_SHELL', 'SHELL_PACKAGER_TEST_MACOS_SFX'] as $env) {
            $path = getenv($env);

            if (!\is_string($path) || !is_file($path)) {
                self::markTestSkipped("Set {$env} (and its sibling) to the pinned macOS runtimes.");
            }

            $m = MachO::read($path);
            self::assertSame(MacAppTest::realArch(), $m->arch(), $env);
            self::assertSame(0, $m->appendedBytes(), $env);
            self::assertNotNull($m->minos, $env);
            self::assertTrue(MachO::versionAtMost($m->minos, '12.0'), "{$env}: minos {$m->minos}");
        }
    }
}
