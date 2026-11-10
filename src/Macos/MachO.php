<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Macos;

use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;

/**
 * What the packager needs to know about a Mach-O executable, read in PHP so it works on any host
 * (no `otool`/`vtool`): its CPU, its minimum macOS, and whether anything is appended past its image.
 *
 * - **minos** comes from `LC_BUILD_VERSION` (or the older `LC_VERSION_MIN_MACOSX`). It is the floor
 *   the binary was linked for, which `Info.plist`'s `LSMinimumSystemVersion` must not undercut.
 * - **The image ends** where its last segment ends: the largest `fileoff + filesize` over every
 *   `LC_SEGMENT_64` (the code signature lives inside `__LINKEDIT`, so it is covered). A file longer
 *   than that carries an appended payload: a phpmicro SFX with its PHAR still attached, which
 *   codesign would seal past the PHAR and so corrupt it (grafida/grafida#88, ADR 0001).
 *
 * Only thin 64-bit little-endian images are accepted: the shell is arm64 only, and a fat binary
 * would need a slice chosen for it.
 */
final readonly class MachO
{
    private const MH_MAGIC_64           = 0xfeedfacf;
    private const FAT_MAGIC             = 0xcafebabe;
    private const LC_SEGMENT_64         = 0x19;
    private const LC_VERSION_MIN_MACOSX = 0x24;
    private const LC_BUILD_VERSION      = 0x32;
    private const PLATFORM_MACOS        = 1;

    public const CPU_ARM64  = 0x0100000c;
    public const CPU_X86_64 = 0x01000007;

    public function __construct(
        public string $path,
        public int $cpuType,
        public ?string $minos,
        public int $imageEnd,
        public int $fileSize,
    ) {
    }

    public static function read(string $path): self
    {
        $data = Filesystem::read($path);
        $size = \strlen($data);

        if ($size < 32) {
            throw new PackagerException("{$path} is not a Mach-O executable (too short).");
        }

        $magic = self::u32($data, 0);

        if ($magic === self::FAT_MAGIC || self::u32be($data, 0) === self::FAT_MAGIC) {
            throw new PackagerException("{$path} is a universal (fat) binary; a thin arm64 one is expected.");
        }

        if ($magic !== self::MH_MAGIC_64) {
            throw new PackagerException("{$path} is not a 64-bit Mach-O executable.");
        }

        $cpu    = self::u32($data, 4);
        $ncmds  = self::u32($data, 16);
        $offset = 32;
        $minos  = null;
        $end    = 0;

        for ($i = 0; $i < $ncmds; $i++) {
            if ($offset + 8 > $size) {
                throw new PackagerException("{$path}: load command {$i} runs past the end of the file.");
            }

            $cmd     = self::u32($data, $offset);
            $cmdsize = self::u32($data, $offset + 4);

            if ($cmdsize < 8 || $offset + $cmdsize > $size) {
                throw new PackagerException("{$path}: load command {$i} has a bad size.");
            }

            if ($cmd === self::LC_SEGMENT_64) {
                // segname[16] at +8, vmaddr +24, vmsize +32, fileoff +40, filesize +48
                $end = max($end, self::u64($data, $offset + 40) + self::u64($data, $offset + 48));
            } elseif ($cmd === self::LC_BUILD_VERSION && self::u32($data, $offset + 8) === self::PLATFORM_MACOS) {
                $minos = self::version(self::u32($data, $offset + 12));
            } elseif ($cmd === self::LC_VERSION_MIN_MACOSX && $minos === null) {
                $minos = self::version(self::u32($data, $offset + 8));
            }

            $offset += $cmdsize;
        }

        return new self($path, $cpu, $minos, $end, $size);
    }

    public function isArm64(): bool
    {
        return $this->cpuType === self::CPU_ARM64;
    }

    /** Bytes past the image: a payload appended to the executable. */
    public function appendedBytes(): int
    {
        return max(0, $this->fileSize - $this->imageEnd);
    }

    /** True when $a <= $b for dotted versions ("12.0" <= "12.0.1"). */
    public static function versionAtMost(string $a, string $b): bool
    {
        return version_compare(self::normal($a), self::normal($b), '<=');
    }

    private static function normal(string $version): string
    {
        $parts = array_map('intval', explode('.', $version));

        return implode('.', array_pad($parts, 3, 0));
    }

    /** `xxxx.yy.zz` packed as nibbles: 0x000C0000 is 12.0, 0x000F0500 is 15.5. */
    private static function version(int $packed): string
    {
        $major = $packed >> 16;
        $minor = ($packed >> 8) & 0xff;
        $patch = $packed & 0xff;

        return $patch === 0 ? "{$major}.{$minor}" : "{$major}.{$minor}.{$patch}";
    }

    private static function u32(string $data, int $at): int
    {
        /** @var array{1: int} $v */
        $v = unpack('V', $data, $at);

        return $v[1];
    }

    private static function u32be(string $data, int $at): int
    {
        /** @var array{1: int} $v */
        $v = unpack('N', $data, $at);

        return $v[1];
    }

    private static function u64(string $data, int $at): int
    {
        /** @var array{1: int} $v */
        $v = unpack('P', $data, $at);

        return $v[1];
    }
}
