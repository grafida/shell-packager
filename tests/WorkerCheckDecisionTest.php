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
use Grafida\ShellPackager\Phar\WorkerCheck;
use Grafida\ShellPackager\Platform;
use PHPUnit\Framework\TestCase;

/**
 * Where the packaged worker can be run before it ships (grafida/grafida#92): natively, or an x86_64
 * macOS worker on an arm64 Mac under Rosetta 2, and nowhere else. Pure: no SFX, any host.
 */
final class WorkerCheckDecisionTest extends TestCase
{
    public function testNativelyOnTheTargetsOsAndArchitecture(): void
    {
        $never = static fn (): bool => self::fail('Rosetta is asked about only for x86_64 macOS on arm64');

        self::assertTrue(WorkerCheck::decide('Linux', Arch::X86_64, Arch::X86_64, Platform::Linux, $never));
        self::assertTrue(WorkerCheck::decide('Darwin', Arch::Aarch64, Arch::Aarch64, Platform::Macos, $never));
        self::assertTrue(WorkerCheck::decide('Darwin', Arch::X86_64, Arch::X86_64, Platform::Macos, $never), 'an Intel Mac');
        self::assertTrue(WorkerCheck::decide('Windows', Arch::X86_64, Arch::X86_64, Platform::Windows, $never));

        self::assertFalse(WorkerCheck::decide('Darwin', Arch::Aarch64, Arch::Aarch64, Platform::Linux, $never));
        self::assertFalse(WorkerCheck::decide('Linux', Arch::Aarch64, Arch::X86_64, Platform::Linux, $never));
        self::assertFalse(WorkerCheck::decide('Darwin', Arch::X86_64, Arch::Aarch64, Platform::Macos, $never), 'no arm64 on Intel');
        self::assertFalse(WorkerCheck::decide('Linux', null, Arch::X86_64, Platform::Linux, $never));
    }

    public function testAnX86WorkerOnAnArm64MacNeedsRosetta(): void
    {
        $asked = 0;
        $with    = static function () use (&$asked): bool { $asked++; return true; };
        $without = static function () use (&$asked): bool { $asked++; return false; };

        self::assertTrue(WorkerCheck::decide('Darwin', Arch::Aarch64, Arch::X86_64, Platform::Macos, $with));
        self::assertFalse(WorkerCheck::decide('Darwin', Arch::Aarch64, Arch::X86_64, Platform::Macos, $without), 'skipped, never faked');
        self::assertSame(2, $asked);

        // Rosetta translates macOS code only.
        self::assertFalse(WorkerCheck::decide('Darwin', Arch::Aarch64, Arch::X86_64, Platform::Linux, $with));
        self::assertFalse(WorkerCheck::decide('Darwin', Arch::Aarch64, Arch::X86_64, Platform::Windows, $with));
        self::assertSame(2, $asked);
    }

    public function testThisMachinesAnswerIsConsistent(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin' || Arch::host() !== Arch::Aarch64) {
            self::assertFalse(WorkerCheck::rosetta() && \PHP_OS_FAMILY !== 'Darwin');
            self::assertSame('', WorkerCheck::translation(Arch::X86_64, Platform::Macos));

            return;
        }

        self::assertSame(WorkerCheck::rosetta(), WorkerCheck::canRun(Arch::X86_64, Platform::Macos));
        self::assertSame(' (x86_64 under Rosetta 2)', WorkerCheck::translation(Arch::X86_64, Platform::Macos));
        self::assertSame('', WorkerCheck::translation(Arch::Aarch64, Platform::Macos));
    }
}
