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
 * What the packager needs to know about a PE32+ executable, read in PHP so it works on any host
 * (the Mac signs and packages Windows builds; grafida/grafida#91): its machine and subsystem, its
 * sections and data directories, the DLLs it imports, where its image ends, and its certificate
 * table.
 *
 * - **The image ends** where its last section's raw data ends. Bytes past that which are not the
 *   certificate table are an **overlay**: a phpmicro SFX with its PHAR still appended, which
 *   Authenticode would sign past and so corrupt (the signature goes after the PHAR, and the
 *   PHAR's own trailing signature no longer verifies). Such a file is refused.
 * - **The certificate table** (data directory 4) is a file offset, not an RVA, and always sits at
 *   the end of the file; an input that already has one is refused, since rewriting its resources
 *   would invalidate it.
 *
 * PE32+ and PE32 are both read: the shell and the SFX are x86_64 (PE32+), but NSIS writes an
 * installer and its uninstaller from x86 stubs (PE32), and those are signed too. Only the AMD64
 * ones get resources written ({@see ResourceWriter}).
 */
final readonly class PeFile
{
    public const MACHINE_AMD64 = 0x8664;
    public const SUBSYSTEM_GUI = 2;
    public const SUBSYSTEM_CONSOLE = 3;

    public const DIR_IMPORT = 1;
    public const DIR_RESOURCE = 2;
    public const DIR_SECURITY = 4;
    public const DIR_BASERELOC = 5;
    public const DIR_DELAY_IMPORT = 13;

    private const PE32 = 0x10b;
    private const PE32_PLUS = 0x20b;

    /**
     * @param list<PeSection>       $sections in section-table order
     * @param list<array{int, int}> $directories RVA (a file offset for DIR_SECURITY) and size
     */
    public function __construct(
        public string $path,
        public string $data,
        public int $peOffset,
        public int $machine,
        public int $subsystem,
        public int $fileAlignment,
        public int $sectionAlignment,
        public int $sizeOfHeaders,
        public array $sections,
        public array $directories,
        public bool $is64 = true,
    ) {
    }

    public static function read(string $path): self
    {
        return self::parse(Filesystem::read($path), $path);
    }

    public static function parse(string $data, string $what = 'the executable'): self
    {
        $size = \strlen($data);

        if ($size < 0x40 || substr($data, 0, 2) !== 'MZ') {
            throw new PackagerException("{$what} is not a Windows executable (no MZ header).");
        }

        $pe = self::u32($data, 0x3c);

        if ($pe + 24 > $size || substr($data, $pe, 4) !== "PE\0\0") {
            throw new PackagerException("{$what} is not a Windows executable (no PE signature).");
        }

        $coff     = $pe + 4;
        $machine  = self::u16($data, $coff);
        $count    = self::u16($data, $coff + 2);
        $optSize  = self::u16($data, $coff + 16);
        $opt      = $pe + 24;

        $magic = $opt + 2 <= $size ? self::u16($data, $opt) : 0;

        if (!\in_array($magic, [self::PE32, self::PE32_PLUS], true)) {
            throw new PackagerException("{$what} is not a PE32 or PE32+ executable.");
        }

        $is64 = $magic === self::PE32_PLUS;
        $dirs = $is64 ? 112 : 96;

        if ($opt + $optSize > $size || $optSize < $dirs) {
            throw new PackagerException("{$what}: its optional header is truncated.");
        }

        $dirCount    = min(16, self::u32($data, $opt + $dirs - 4));
        $directories = [];

        for ($i = 0; $i < 16; $i++) {
            $directories[] = $i < $dirCount && $opt + $dirs + 8 * $i + 8 <= $opt + $optSize
                ? [self::u32($data, $opt + $dirs + 8 * $i), self::u32($data, $opt + $dirs + 4 + 8 * $i)]
                : [0, 0];
        }

        $table    = $opt + $optSize;
        $sections = [];

        if ($table + 40 * $count > $size) {
            throw new PackagerException("{$what}: the section table runs past the end of the file.");
        }

        for ($i = 0; $i < $count; $i++) {
            $s          = $table + 40 * $i;
            $sections[] = new PeSection(
                name: rtrim(substr($data, $s, 8), "\0"),
                virtualSize: self::u32($data, $s + 8),
                virtualAddress: self::u32($data, $s + 12),
                rawSize: self::u32($data, $s + 16),
                rawPointer: self::u32($data, $s + 20),
                characteristics: self::u32($data, $s + 36),
            );
        }

        return new self(
            path: $what,
            data: $data,
            peOffset: $pe,
            machine: $machine,
            subsystem: self::u16($data, $opt + 68),
            fileAlignment: self::u32($data, $opt + 36),
            sectionAlignment: self::u32($data, $opt + 32),
            sizeOfHeaders: self::u32($data, $opt + 60),
            sections: $sections,
            directories: $directories,
            is64: $is64,
        );
    }

    public function isAmd64(): bool
    {
        return $this->machine === self::MACHINE_AMD64;
    }

    /** Where the optional header starts. */
    public function optionalHeader(): int
    {
        return $this->peOffset + 24;
    }

    /** Where the data directories start (PE32+: 112 bytes into the optional header; PE32: 96). */
    public function dataDirectories(): int
    {
        return $this->optionalHeader() + ($this->is64 ? 112 : 96);
    }

    /** Where the section table starts. */
    public function sectionTable(): int
    {
        return $this->optionalHeader() + self::u16($this->data, $this->peOffset + 4 + 16);
    }

    /** The end of the last section's raw data: where the image proper ends. */
    public function imageEnd(): int
    {
        $end = $this->sizeOfHeaders;

        foreach ($this->sections as $section) {
            if ($section->rawSize > 0) {
                $end = max($end, $section->rawPointer + $section->rawSize);
            }
        }

        return $end;
    }

    /** @return array{int, int} the certificate table's file offset and size ([0, 0] when unsigned) */
    public function certificateTable(): array
    {
        return $this->directories[self::DIR_SECURITY];
    }

    public function isSigned(): bool
    {
        return $this->certificateTable()[1] > 0;
    }

    /** Bytes past the image that are not the certificate table: an appended payload. */
    public function overlayBytes(): int
    {
        [$offset, $size] = $this->certificateTable();
        $end  = $this->imageEnd();
        $tail = \strlen($this->data) - $end;

        if ($size > 0 && $offset >= $end && $offset + $size === \strlen($this->data)) {
            // What lies between the image and the certificate is the overlay, except the zero
            // bytes (fewer than 8) a signer pads with to align the table.
            $gap  = $offset - $end;
            $tail = $gap < 8 && trim(substr($this->data, $end, $gap), "\0") === '' ? 0 : $gap;
        }

        return max(0, $tail);
    }

    /** The section an RVA falls in, if any. */
    public function sectionAt(int $rva): ?int
    {
        foreach ($this->sections as $i => $section) {
            if ($rva >= $section->virtualAddress && $rva < $section->virtualAddress + max($section->virtualSize, $section->rawSize)) {
                return $i;
            }
        }

        return null;
    }

    /** The file offset of an RVA, if it is backed by raw data. */
    public function offsetOf(int $rva): ?int
    {
        $i = $this->sectionAt($rva);

        if ($i === null) {
            return null;
        }

        $section = $this->sections[$i];
        $delta   = $rva - $section->virtualAddress;

        return $delta < $section->rawSize ? $section->rawPointer + $delta : null;
    }

    /**
     * The DLLs it imports, directly and delay-loaded, in table order.
     *
     * @return list<string>
     */
    public function imports(): array
    {
        $names = [];

        foreach ([[self::DIR_IMPORT, 20, 12], [self::DIR_DELAY_IMPORT, 32, 4]] as [$dir, $entrySize, $nameAt]) {
            [$rva] = $this->directories[$dir];
            $at    = $rva > 0 ? $this->offsetOf($rva) : null;

            while ($at !== null && $at + $entrySize <= \strlen($this->data) && trim(substr($this->data, $at, $entrySize), "\0") !== '') {
                $name = $this->offsetOf(self::u32($this->data, $at + $nameAt));

                if ($name !== null) {
                    $end     = strpos($this->data, "\0", $name);
                    $names[] = substr($this->data, $name, ($end === false ? \strlen($this->data) : $end) - $name);
                }

                $at += $entrySize;
            }
        }

        return $names;
    }

    /**
     * The resource directory's raw bytes and its RVA, or null when there are no resources.
     *
     * @return array{data: string, rva: int}|null
     */
    public function resourceData(): ?array
    {
        [$rva, $size] = $this->directories[self::DIR_RESOURCE];
        $at = $rva > 0 && $size > 0 ? $this->offsetOf($rva) : null;

        return $at === null ? null : ['data' => substr($this->data, $at, $size), 'rva' => $rva];
    }

    /**
     * The PE checksum (the optional header's CheckSum field) of $data, as the image loader and
     * `ImageHlp!CheckSumMappedFile` compute it: a 16-bit folded sum of every word except the
     * field itself, plus the file length.
     */
    public static function checksum(string $data, int $checksumOffset): int
    {
        $len  = \strlen($data);
        $data = substr_replace($data, "\0\0\0\0", $checksumOffset, 4) . ($len % 2 === 1 ? "\0" : '');
        $sum  = 0;

        // In 64 KiB chunks: one unpack('v*') of a 20 MB stub would be a ten-million-entry array.
        for ($at = 0, $padded = \strlen($data); $at < $padded; $at += 65536) {
            $words = unpack('v*', substr($data, $at, 65536));

            if ($words === false) {
                throw new PackagerException('Cannot read the image to checksum it.');
            }

            $sum += array_sum($words);
            $sum  = ($sum & 0xffff) + ($sum >> 16);
            $sum  = ($sum & 0xffff) + ($sum >> 16);
        }

        $sum = ($sum & 0xffff) + ($sum >> 16);

        return ($sum + $len) & 0xffffffff;
    }

    public static function u16(string $data, int $at): int
    {
        /** @var array{1: int} $v */
        $v = unpack('v', $data, $at);

        return $v[1];
    }

    public static function u32(string $data, int $at): int
    {
        /** @var array{1: int} $v */
        $v = unpack('V', $data, $at);

        return $v[1];
    }
}
