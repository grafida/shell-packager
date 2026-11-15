<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

/**
 * A minimal little-endian ELF64 file shaped like the AppImage type-2 runtime where it matters: `AI\x02`
 * in e_ident padding (offset 8), a `.upd_info` section of $updateSize zero bytes, a `.shstrtab`.
 * Not executable code; enough for the packager's ELF reader.
 */
final class ElfBuilder
{
    public static function runtime(int $updateSize = 1024, bool $withSection = true): string
    {
        $names   = "\0" . ($withSection ? ".upd_info\0" : '') . ".shstrtab\0";
        $strtab  = $withSection ? 11 : 1;
        $content = $withSection ? str_repeat("\0", $updateSize) : '';
        $dataAt  = 64;
        $strAt   = $dataAt + \strlen($content);
        $shoff   = $strAt + \strlen($names);
        $count   = $withSection ? 3 : 2;

        $header = "\x7fELF\x02\x01\x01\x00AI\x02\x00\x00\x00\x00\x00"
            . pack('vvVPPPVvvvvvv', 3, 62, 1, 0, 0, $shoff, 0, 64, 0, 0, 64, $count, $count - 1);

        $section = static fn (int $name, int $type, int $offset, int $size): string => pack('VVPPPPVVPP', $name, $type, 0, 0, $offset, $size, 0, 0, 1, 0);
        $table   = $section(0, 0, 0, 0);

        if ($withSection) {
            $table .= $section(1, 1, $dataAt, $updateSize);
        }

        $table .= $section($strtab, 3, $strAt, \strlen($names));

        return $header . $content . $names . $table . "RUNTIME-TAIL";
    }
}
