<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\Macos\MachO;

/**
 * Synthetic Mach-O files for the tests: a header, one `__TEXT` segment covering the whole image,
 * and a build-version command. Not runnable, but exactly what {@see MachO} reads.
 */
final class MachOBuilder
{
    public static function bytes(
        int $cpu = MachO::CPU_ARM64,
        ?string $minos = '12.0',
        int $image = 4096,
        string $appended = '',
        bool $legacyVersionCommand = false,
    ): string {
        $segment = pack('VV', 0x19, 72) . str_pad('__TEXT', 16, "\0")
            . pack('PPPP', 0x100000000, $image, 0, $image) . pack('VVVV', 5, 5, 0, 0);
        $commands = [$segment];

        if ($minos !== null) {
            $packed     = self::pack($minos);
            $commands[] = $legacyVersionCommand
                ? pack('VVVV', 0x24, 16, $packed, $packed)
                : pack('VVVVVV', 0x32, 24, 1, $packed, $packed, 0);
        }

        $cmds   = implode('', $commands);
        $header = pack('VVVVVVVV', 0xfeedfacf, $cpu, 0, 2, \count($commands), \strlen($cmds), 0, 0);

        return str_pad($header . $cmds, $image, "\0") . $appended;
    }

    private static function pack(string $version): int
    {
        [$major, $minor, $patch] = array_map('intval', array_pad(explode('.', $version), 3, '0'));

        return ($major << 16) | ($minor << 8) | $patch;
    }
}
