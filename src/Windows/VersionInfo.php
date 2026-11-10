<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Windows;

use Grafida\ShellPackager\PackagerException;

/**
 * An executable's version resource (`RT_VERSION`, a `VS_VERSIONINFO` block): the numeric file and
 * product versions, and the strings Windows shows. **`FileDescription` is what Task Manager names
 * the process by**, and Explorer's Details tab shows the rest.
 *
 * One string table, US English in UTF-16 (`040904B0`), with a matching `Translation`. Every node
 * is `wLength, wValueLength, wType, szKey`, padded to 32 bits, then its value and its children,
 * each child starting on a 32-bit boundary; `wLength` covers the node and its children, not the
 * padding after it.
 */
final readonly class VersionInfo
{
    private const LANG_CODEPAGE = '040904B0';
    private const TRANSLATION = 0x04b00409;   // en-US (0x0409), Unicode (1200)

    /**
     * @param array{int, int, int, int} $fileVersion
     * @param array{int, int, int, int} $productVersion
     * @param array<string, string>     $strings  CompanyName, FileDescription, FileVersion, …
     */
    public function __construct(
        public array $fileVersion,
        public array $productVersion,
        public array $strings,
    ) {
    }

    /**
     * The four 16-bit parts of a version: major.minor.patch from its leading numbers, and a
     * stability tier from what follows, so a pre-release sorts below its release: alpha N → N − 1
     * (0–9), beta N → 9 + N (10–100), rc N → 899 + N (900–999), none → 1000. A fourth number in
     * the version is dropped: the tier is the fourth part.
     *
     * @return array{int, int, int, int}
     */
    public static function numeric(string $version): array
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.\d+)?(.*)$/', trim($version), $m) !== 1) {
            throw new PackagerException("The version '{$version}' does not start with a number.");
        }

        $parts = [(int) $m[1], (int) $m[2], (int) $m[3]];

        foreach ($parts as $part) {
            if ($part > 0xffff) {
                throw new PackagerException("The version '{$version}' has a part above 65535, which a Windows version cannot hold.");
            }
        }

        $rest = trim($m[4]);
        $tier = 1000;

        if ($rest !== '') {
            if (preg_match('/^[.\-+]?(alpha|a|beta|b|rc|dev)\.?(\d+)?$/i', $rest, $s) !== 1) {
                throw new PackagerException("The version '{$version}' has an unrecognised suffix '{$rest}' (expected alpha, beta, rc or dev, e.g. '-beta2').");
            }

            $n    = max(1, (int) ($s[2] ?? 1));
            $tier = match (strtolower($s[1])) {
                'a', 'alpha', 'dev' => min(9, $n - 1),
                'b', 'beta'         => min(100, 9 + $n),
                default             => min(999, 899 + $n),
            };
        }

        return [$parts[0], $parts[1], $parts[2], $tier];
    }

    /** @param array{int, int, int, int} $version */
    public static function dotted(array $version): string
    {
        return implode('.', $version);
    }

    public function resource(int $lang = 0x0409): ResourceEntry
    {
        return new ResourceEntry(Resources::RT_VERSION, 1, $lang, $this->bytes());
    }

    public function bytes(): string
    {
        $fixed = pack(
            'V13',
            0xfeef04bd,            // signature
            0x00010000,            // structure version
            ($this->fileVersion[0] << 16) | $this->fileVersion[1],
            ($this->fileVersion[2] << 16) | $this->fileVersion[3],
            ($this->productVersion[0] << 16) | $this->productVersion[1],
            ($this->productVersion[2] << 16) | $this->productVersion[3],
            0x3f,                  // file flags mask
            0,                     // file flags
            0x00040004,            // VOS_NT_WINDOWS32
            0x1,                   // VFT_APP
            0,
            0,
            0,
        );

        $strings = [];

        foreach ($this->strings as $key => $value) {
            if ($value === '') {
                continue;
            }

            $text      = self::utf16($value . "\0");
            $strings[] = self::node($key, $text, intdiv(\strlen($text), 2), 1, []);
        }

        $table    = self::node(self::LANG_CODEPAGE, '', 0, 1, $strings);
        $stringFi = self::node('StringFileInfo', '', 0, 1, [$table]);
        $var      = self::node('Translation', pack('V', self::TRANSLATION), 4, 0, []);
        $varFi    = self::node('VarFileInfo', '', 0, 1, [$var]);

        return self::node('VS_VERSION_INFO', $fixed, \strlen($fixed), 0, [$stringFi, $varFi]);
    }

    /**
     * Reads one back (for the tests and the checks): the numeric versions and the strings.
     *
     * @return array{fileVersion: array{int, int, int, int}, productVersion: array{int, int, int, int}, strings: array<string, string>}
     */
    public static function decode(string $data): array
    {
        $root  = self::parseNode($data, 0);
        $value = $root->value;

        if ($root->key !== 'VS_VERSION_INFO' || \strlen($value) < 52 || PeFile::u32($value, 0) !== 0xfeef04bd) {
            throw new PackagerException('Not a VS_VERSIONINFO block.');
        }

        $v       = static fn (int $at): array => [PeFile::u32($value, $at) >> 16, PeFile::u32($value, $at) & 0xffff, PeFile::u32($value, $at + 4) >> 16, PeFile::u32($value, $at + 4) & 0xffff];
        $strings = [];

        foreach ($root->children as $info) {
            if ($info->key !== 'StringFileInfo') {
                continue;
            }

            foreach ($info->children as $table) {
                foreach ($table->children as $string) {
                    $strings[$string->key] = rtrim(mb_convert_encoding($string->value, 'UTF-8', 'UTF-16LE'), "\0");
                }
            }
        }

        return ['fileVersion' => $v(8), 'productVersion' => $v(16), 'strings' => $strings];
    }

    /** @param list<string> $children already-encoded child nodes */
    private static function node(string $key, string $value, int $valueLength, int $type, array $children): string
    {
        $node = self::pad(pack('vvv', 0, $valueLength, $type) . self::utf16($key . "\0")) . $value;

        foreach ($children as $child) {
            $node = self::pad($node) . $child;
        }

        return substr_replace($node, pack('v', \strlen($node)), 0, 2);
    }

    private static function parseNode(string $data, int $at): VersionNode
    {
        $length = PeFile::u16($data, $at);
        $valLen = PeFile::u16($data, $at + 2);
        $type   = PeFile::u16($data, $at + 4);
        $end    = strpos($data, "\0\0", $at + 6);

        while ($end !== false && ($end - $at - 6) % 2 !== 0) {
            $end = strpos($data, "\0\0", $end + 1);
        }

        if ($end === false || $length < 6 || $at + $length > \strlen($data)) {
            throw new PackagerException('Malformed version resource.');
        }

        $key      = mb_convert_encoding(substr($data, $at + 6, $end - $at - 6), 'UTF-8', 'UTF-16LE');
        $valueAt  = self::align($end + 2 - $at) + $at;
        $valBytes = $type === 1 ? 2 * $valLen : $valLen;
        $value    = substr($data, $valueAt, $valBytes);
        $children = [];
        $child    = self::align($valueAt + $valBytes - $at) + $at;

        while ($child < $at + $length) {
            $children[] = self::parseNode($data, $child);
            $child      = self::align($child + PeFile::u16($data, $child) - $at) + $at;
        }

        return new VersionNode($key, $value, $children);
    }

    private static function utf16(string $s): string
    {
        return mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
    }

    private static function align(int $n): int
    {
        return ($n + 3) & ~3;
    }

    private static function pad(string $s): string
    {
        return str_pad($s, self::align(\strlen($s)), "\0");
    }
}
