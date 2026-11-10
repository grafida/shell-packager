<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\Windows\PeFile;
use Grafida\ShellPackager\Windows\ResourceEntry;
use Grafida\ShellPackager\Windows\Resources;

/**
 * Synthetic PE files for the tests: the headers, a `.text` section, a `.rsrc` holding a manifest,
 * and a `.reloc` with one block, laid out as MSVC's and LLVM's linkers do. Not runnable, but
 * exactly what {@see PeFile} and the resource writer read.
 */
final class PeBuilder
{
    public const MANIFEST = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><assembly xmlns="urn:schemas-microsoft-com:asm.v1" manifestVersion="1.0"/>';
    public const RELOC = "\x00\x10\x00\x00\x0c\x00\x00\x00\x10\xa0\x00\x00";

    /**
     * @param list<ResourceEntry>|null $resources null: just the manifest
     */
    public static function bytes(
        int $machine = PeFile::MACHINE_AMD64,
        int $subsystem = PeFile::SUBSYSTEM_GUI,
        bool $pe32 = false,
        ?array $resources = null,
        bool $withReloc = true,
        bool $sectionAfterResources = false,
        string $overlay = '',
        string $certificate = '',
    ): string {
        $fa   = 0x200;
        $sa   = 0x1000;
        $tree = new Resources($resources ?? [new ResourceEntry(Resources::RT_MANIFEST, 1, 0x0409, self::MANIFEST)]);

        // name, characteristics, raw data, its RVA (filled in below)
        $sections = [['.text', 0x60000020, str_repeat("\xcc", 0x80)]];
        $rsrcIdx  = 1;
        $sections[] = ['.rsrc', 0x40000040, ''];

        if ($sectionAfterResources) {
            $sections[] = ['.data2', 0xc0000040, str_repeat("\x01", 16)];
        }

        if ($withReloc) {
            $sections[] = ['.reloc', 0x42000040, self::RELOC];
        }

        $optSize  = $pe32 ? 224 : 240;
        $headers  = 0x400;
        $va       = $sa;
        $raw      = $headers;
        $table    = '';
        $body     = '';
        $dirs     = array_fill(0, 16, [0, 0]);

        foreach ($sections as $i => [$name, $characteristics, $data]) {
            if ($i === $rsrcIdx) {
                $data = $tree->serialize($va);
                $dirs[PeFile::DIR_RESOURCE] = [$va, \strlen($data)];
            }

            if ($name === '.reloc') {
                $dirs[PeFile::DIR_BASERELOC] = [$va, \strlen($data)];
            }

            $rawSize = self::align(\strlen($data), $fa);
            $table  .= str_pad($name, 8, "\0") . pack('VVVVVVvvV', \strlen($data), $va, $rawSize, $raw, 0, 0, 0, 0, $characteristics);
            $body   .= str_pad($data, $rawSize, "\0");
            $raw    += $rawSize;
            $va     += self::align(\strlen($data), $sa);
        }

        $imageSize = $va;
        $tail      = $overlay;

        if ($certificate !== '') {
            $dirs[PeFile::DIR_SECURITY] = [$raw + \strlen($overlay), \strlen($certificate)];
            $tail                      .= $certificate;
        }

        $coff = pack('vvVVVvv', $machine, \count($sections), 0, 0, 0, $optSize, 0x22);
        $opt  = pack('vCC', $pe32 ? 0x10b : 0x20b, 14, 0) . pack('VVVVV', 0x200, 0x200, 0, 0x1000, 0x1000);
        $opt .= $pe32 ? pack('VV', 0x2000, 0x400000) : pack('P', 0x140000000);
        $opt .= pack('VVvvvvvvVVVVvv', $sa, $fa, 6, 0, 0, 0, 6, 0, 0, $imageSize, $headers, 0, $subsystem, 0x8160);
        $opt .= $pe32 ? pack('VVVVVV', 0x100000, 0x1000, 0x100000, 0x1000, 0, 16) : pack('PPPPVV', 0x100000, 0x1000, 0x100000, 0x1000, 0, 16);

        foreach ($dirs as [$rva, $size]) {
            $opt .= pack('VV', $rva, $size);
        }

        $dos = 'MZ' . str_repeat("\0", 0x3a) . pack('V', 0x40);
        $pe  = $dos . "PE\0\0" . $coff . $opt . $table;

        return str_pad($pe, $headers, "\0") . $body . $tail;
    }

    private static function align(int $n, int $to): int
    {
        return intdiv($n + $to - 1, $to) * $to;
    }
}
