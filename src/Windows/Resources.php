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
 * A PE resource tree (type → name → language → data), read from and written to the format of a
 * `.rsrc` section: three levels of `IMAGE_RESOURCE_DIRECTORY` tables, then the name strings, the
 * `IMAGE_RESOURCE_DATA_ENTRY` descriptors and the data. Offsets inside the tree are relative to
 * its start; a data entry's offset is an **RVA**, so the tree is written for the RVA it will live at.
 *
 * Within a table, named entries come first, sorted case-insensitively, then numeric ones in
 * ascending order: the loader binary-searches both.
 */
final class Resources
{
    public const RT_ICON = 3;
    public const RT_GROUP_ICON = 14;
    public const RT_VERSION = 16;
    public const RT_MANIFEST = 24;

    /** @var list<ResourceEntry> */
    private array $entries = [];

    /** @param list<ResourceEntry> $entries */
    public function __construct(array $entries = [])
    {
        foreach ($entries as $entry) {
            $this->put($entry);
        }
    }

    /** Reads the tree of a `.rsrc` directory whose first byte is at $rva. */
    public static function parse(string $data, int $rva): self
    {
        $tree = new self();

        foreach (self::table($data, 0) as [$type, $typeAt, $typeIsDir]) {
            if (!$typeIsDir) {
                throw new PackagerException('Malformed resources: a type without a name table.');
            }

            foreach (self::table($data, $typeAt) as [$name, $nameAt, $nameIsDir]) {
                if (!$nameIsDir) {
                    throw new PackagerException('Malformed resources: a name without a language table.');
                }

                foreach (self::table($data, $nameAt) as [$lang, $entryAt, $langIsDir]) {
                    if ($langIsDir || !\is_int($lang) || $entryAt + 16 > \strlen($data)) {
                        throw new PackagerException('Malformed resources: a language that is not a data entry.');
                    }

                    $dataRva  = PeFile::u32($data, $entryAt);
                    $size     = PeFile::u32($data, $entryAt + 4);
                    $codePage = PeFile::u32($data, $entryAt + 8);
                    $offset   = $dataRva - $rva;

                    if ($offset < 0 || $offset + $size > \strlen($data)) {
                        throw new PackagerException("Resource {$type}/{$name}/{$lang} points outside the resource directory.");
                    }

                    $tree->put(new ResourceEntry($type, $name, $lang, substr($data, $offset, $size), $codePage));
                }
            }
        }

        return $tree;
    }

    /** @return list<ResourceEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<ResourceEntry> every entry of a type */
    public function ofType(int|string $type): array
    {
        return array_values(array_filter($this->entries, static fn (ResourceEntry $e): bool => $e->type === $type));
    }

    /** Adds an entry, replacing one with the same type, name and language. */
    public function put(ResourceEntry $entry): void
    {
        $this->entries = array_values(array_filter(
            $this->entries,
            static fn (ResourceEntry $e): bool => !($e->type === $entry->type && $e->name === $entry->name && $e->lang === $entry->lang),
        ));
        $this->entries[] = $entry;
    }

    /** Removes every entry of a type. */
    public function removeType(int|string $type): void
    {
        $this->entries = array_values(array_filter($this->entries, static fn (ResourceEntry $e): bool => $e->type !== $type));
    }

    /** The tree in `.rsrc` format, for a section starting at $rva. */
    public function serialize(int $rva): string
    {
        // type => name => lang => entry, each level in the loader's order.
        $tree = [];

        foreach ($this->entries as $e) {
            $tree[self::key($e->type)][self::key($e->name)][$e->lang] = $e;
        }

        $tree = self::sorted($tree);

        foreach ($tree as $t => $names) {
            $tree[$t] = self::sorted($names);

            foreach ($tree[$t] as $n => $langs) {
                ksort($langs);
                $tree[$t][$n] = $langs;
            }
        }

        // Layout: every table (root, types, names), then strings, then data entries, then data.
        $tableSize = static fn (int $entries): int => 16 + 8 * $entries;
        $tables    = $tableSize(\count($tree));

        foreach ($tree as $names) {
            $tables += $tableSize(\count($names));

            foreach ($names as $langs) {
                $tables += $tableSize(\count($langs));
            }
        }

        $strings = '';
        $stringAt = [];

        foreach ($tree as $t => $names) {
            foreach (array_merge([$t], array_keys($names)) as $key) {
                if (str_starts_with($key, 's:') && !isset($stringAt[$key])) {
                    $name           = mb_convert_encoding(substr($key, 2), 'UTF-16LE', 'UTF-8');
                    $stringAt[$key] = $tables + \strlen($strings);
                    $strings       .= pack('v', intdiv(\strlen($name), 2)) . $name;
                }
            }
        }

        $strings     = self::pad($strings, 4);
        $entriesAt   = $tables + \strlen($strings);
        $count       = 0;

        foreach ($tree as $names) {
            foreach ($names as $langs) {
                $count += \count($langs);
            }
        }

        $dataAt = self::align($entriesAt + 16 * $count, 8);

        $entries  = '';
        $blobs    = '';
        $typeDirs = '';
        $nameDirs = '';
        $root     = self::header($tree);

        // Offsets of the type tables, then the name tables, in order.
        $typeAt = [];
        $at     = $tableSize(\count($tree));

        foreach ($tree as $t => $names) {
            $typeAt[$t] = $at;
            $at        += $tableSize(\count($names));
        }

        $nameAt = [];

        foreach ($tree as $t => $names) {
            foreach ($names as $n => $langs) {
                $nameAt[$t][$n] = $at;
                $at            += $tableSize(\count($langs));
            }
        }

        foreach ($tree as $t => $names) {
            $root     .= self::entry($t, $stringAt) . pack('V', 0x80000000 | $typeAt[$t]);
            $typeDir   = self::header($names);

            foreach ($names as $n => $langs) {
                $typeDir .= self::entry($n, $stringAt) . pack('V', 0x80000000 | $nameAt[$t][$n]);
                $nameDir  = self::header($langs);

                foreach ($langs as $lang => $e) {
                    $entryOffset = $entriesAt + \strlen($entries);
                    $nameDir    .= pack('VV', $lang, $entryOffset);
                    $blobOffset  = $dataAt + \strlen($blobs);
                    $entries    .= pack('VVVV', $rva + $blobOffset, \strlen($e->data), $e->codePage, 0);
                    $blobs       = self::pad($blobs . $e->data, 8);
                }

                $nameDirs .= $nameDir;
            }

            $typeDirs .= $typeDir;
        }

        return self::pad($root . $typeDirs . $nameDirs . $strings . $entries, 8) . $blobs;
    }

    /**
     * The entries of one directory table: [id or name, offset of what it points to, is a table].
     *
     * @return list<array{int|string, int, bool}>
     */
    private static function table(string $data, int $at): array
    {
        if ($at + 16 > \strlen($data)) {
            throw new PackagerException('Malformed resources: a table runs past the end.');
        }

        $count = PeFile::u16($data, $at + 12) + PeFile::u16($data, $at + 14);
        $out   = [];

        for ($i = 0; $i < $count; $i++) {
            $e = $at + 16 + 8 * $i;

            if ($e + 8 > \strlen($data)) {
                throw new PackagerException('Malformed resources: a table entry runs past the end.');
            }

            $name   = PeFile::u32($data, $e);
            $target = PeFile::u32($data, $e + 4);

            if (($name & 0x80000000) !== 0) {
                $s    = $name & 0x7fffffff;
                $len  = PeFile::u16($data, $s);
                $name = mb_convert_encoding(substr($data, $s + 2, 2 * $len), 'UTF-8', 'UTF-16LE');
            }

            $out[] = [$name, $target & 0x7fffffff, ($target & 0x80000000) !== 0];
        }

        return $out;
    }

    /** A sortable, collision-free key: `i:<id>` or `s:<name>`. */
    private static function key(int|string $id): string
    {
        return \is_int($id) ? 'i:' . $id : 's:' . $id;
    }

    /**
     * Named keys first, case-insensitively; then ids, numerically.
     *
     * @template T
     * @param array<string, T> $level
     * @return array<string, T>
     */
    private static function sorted(array $level): array
    {
        uksort($level, static function (string $a, string $b): int {
            $an = str_starts_with($a, 's:');
            $bn = str_starts_with($b, 's:');

            if ($an !== $bn) {
                return $an ? -1 : 1;
            }

            return $an ? strcmp(strtoupper($a), strtoupper($b)) : (int) substr($a, 2) <=> (int) substr($b, 2);
        });

        return $level;
    }

    /** @param array<array-key, mixed> $level */
    private static function header(array $level): string
    {
        $named = \count(array_filter(array_keys($level), static fn (int|string $k): bool => str_starts_with((string) $k, 's:')));

        return pack('VVvvvv', 0, 0, 0, 0, $named, \count($level) - $named);
    }

    /** @param array<string, int> $stringAt */
    private static function entry(int|string $key, array $stringAt): string
    {
        $key = (string) $key;

        return str_starts_with($key, 's:')
            ? pack('V', 0x80000000 | $stringAt[$key])
            : pack('V', (int) substr($key, 2));
    }

    private static function align(int $n, int $to): int
    {
        return intdiv($n + $to - 1, $to) * $to;
    }

    private static function pad(string $s, int $to): string
    {
        return str_pad($s, self::align(\strlen($s), $to), "\0");
    }
}
