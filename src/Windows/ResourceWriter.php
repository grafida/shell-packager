<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Windows;

use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;

/**
 * Gives an executable its icon and version resources, in PHP, on any host (no `rcedit`, which is
 * Windows-only; grafida/grafida#91). The existing resources are kept except the ones replaced
 * (`RT_ICON`, `RT_GROUP_ICON`, `RT_VERSION`): the application manifest stays.
 *
 * The resource section is rebuilt where it is. It must be the last section, or be followed only by
 * the base-relocation section, which is then moved up behind it: nothing refers to `.reloc` but its
 * data directory (its blocks name the pages they fix up, which do not move). That is the layout
 * MSVC's and LLVM's linkers produce (`… .rsrc .reloc`); anything else is refused, never patched.
 * The headers' image size and checksum follow.
 *
 * Refused as input: a file with an overlay (an SFX with its payload still appended) or with a
 * certificate table (signed already: the signature would no longer match). So this runs before
 * signing, on the bare stub and the shell.
 */
final class ResourceWriter
{
    /** Writes $path's new resources into it: the icon (if any) and the version. */
    public static function apply(string $path, ?IconFile $icon, ?VersionInfo $version): void
    {
        $pe    = PeFile::read($path);
        $tree  = self::existing($pe);

        if ($icon !== null) {
            $tree->removeType(Resources::RT_ICON);
            $tree->removeType(Resources::RT_GROUP_ICON);

            foreach ($icon->resources() as $entry) {
                $tree->put($entry);
            }
        }

        if ($version !== null) {
            $tree->removeType(Resources::RT_VERSION);
            $tree->put($version->resource());
        }

        Filesystem::write($path, self::rewrite($pe, $tree));
    }

    /** The resources of $pe as they are now. */
    public static function existing(PeFile $pe): Resources
    {
        $r = $pe->resourceData();

        return $r === null ? new Resources() : Resources::parse($r['data'], $r['rva']);
    }

    /** $pe's bytes with $tree as its resources. */
    public static function rewrite(PeFile $pe, Resources $tree): string
    {
        if (!$pe->isAmd64() || !$pe->is64) {
            throw new PackagerException("{$pe->path} is not an x86_64 (AMD64) executable.");
        }

        if ($pe->isSigned()) {
            throw new PackagerException("{$pe->path} is signed already: its resources must be written before it is signed.");
        }

        if ($pe->overlayBytes() > 0) {
            throw new PackagerException(
                "{$pe->path} carries {$pe->overlayBytes()} bytes past its image: an SFX with its payload still appended. "
                . 'Package the bare stub and its sibling .phar.'
            );
        }

        [$rsrcRva] = $pe->directories[PeFile::DIR_RESOURCE];
        $sections  = $pe->sections;
        $last      = \count($sections) - 1;
        $index     = $rsrcRva > 0 ? $pe->sectionAt($rsrcRva) : null;

        if ($index === null) {
            throw new PackagerException("{$pe->path} has no resource section to rewrite (a linker always emits one for a manifest).");
        }

        $reloc = null;

        if ($index !== $last) {
            [$relocRva] = $pe->directories[PeFile::DIR_BASERELOC];

            if ($index !== $last - 1 || $relocRva === 0 || $pe->sectionAt($relocRva) !== $last) {
                throw new PackagerException(
                    "{$pe->path}: its resource section ({$sections[$index]->name}) is followed by sections other than the "
                    . 'base relocations; rewriting it would move code or data. This layout is not supported.'
                );
            }

            $reloc = $sections[$last];
        }

        $rsrc  = $sections[$index];
        $start = $rsrc->virtualAddress;

        if ($rsrcRva !== $start) {
            throw new PackagerException("{$pe->path}: the resource directory does not start its section.");
        }

        $fa   = $pe->fileAlignment;
        $sa   = $pe->sectionAlignment;
        $blob = $tree->serialize($start);
        $raw  = self::align(\strlen($blob), $fa);
        $data = substr($pe->data, 0, $rsrc->rawPointer) . str_pad($blob, $raw, "\0");
        $opt  = $pe->optionalHeader();
        $tbl  = $pe->sectionTable();

        // The resource section header: virtual size, raw size (pointer and address unchanged).
        $data = self::put32($data, $tbl + 40 * $index + 8, \strlen($blob));
        $data = self::put32($data, $tbl + 40 * $index + 16, $raw);
        $data = self::put32($data, $pe->dataDirectories() + 8 * PeFile::DIR_RESOURCE + 4, \strlen($blob));
        $end  = $start + self::align(\strlen($blob), $sa);

        if ($reloc !== null) {
            $relocAt    = $rsrc->rawPointer + $raw;
            $relocBytes = substr($pe->data, $reloc->rawPointer, $reloc->rawSize);
            $data      .= $relocBytes;
            $shift      = $end - $reloc->virtualAddress;
            [$relocRva] = $pe->directories[PeFile::DIR_BASERELOC];
            $data       = self::put32($data, $tbl + 40 * $last + 12, $end);
            $data       = self::put32($data, $tbl + 40 * $last + 20, $relocAt);
            $data       = self::put32($data, $pe->dataDirectories() + 8 * PeFile::DIR_BASERELOC, $relocRva + $shift);
            $end       += self::align($reloc->virtualSize, $sa);
        }

        // SizeOfInitializedData follows the raw sizes; SizeOfImage the last section's end.
        $initialised = 0;

        foreach (PeFile::parse($data, $pe->path)->sections as $section) {
            if (($section->characteristics & 0x40) !== 0) {
                $initialised += $section->rawSize;
            }
        }

        $data = self::put32($data, $opt + 8, $initialised);
        $data = self::put32($data, $opt + 56, $end);
        $data = self::put32($data, $opt + 64, PeFile::checksum($data, $opt + 64));

        $check = PeFile::parse($data, $pe->path);

        if ($check->overlayBytes() !== 0 || $check->imageEnd() !== \strlen($data)) {
            throw new PackagerException("{$pe->path}: the rewritten image does not end at its last section.");
        }

        return $data;
    }

    private static function put32(string $data, int $at, int $value): string
    {
        return substr_replace($data, pack('V', $value), $at, 4);
    }

    private static function align(int $n, int $to): int
    {
        return intdiv($n + $to - 1, $to) * $to;
    }
}
