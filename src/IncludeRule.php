<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager;

/**
 * One `phar.include` entry: every file under `directory`, optionally narrowed by `name` (glob
 * patterns on the file name; any may match), `notName` (patterns that exclude by file name) and
 * `notPath` (path prefixes, relative to `directory`, that exclude a whole subtree).
 */
final readonly class IncludeRule
{
    /**
     * @param list<string> $name
     * @param list<string> $notName
     * @param list<string> $notPath
     */
    public function __construct(
        public string $directory,
        public array $name = [],
        public array $notName = [],
        public array $notPath = [],
    ) {
    }

    /** @param array<mixed> $rule */
    public static function fromArray(array $rule, string $where): self
    {
        $directory = $rule['directory'] ?? null;

        if (!\is_string($directory) || $directory === '' || str_contains($directory, '..')) {
            throw new PackagerException("{$where}: 'directory' must be a path inside the app.");
        }

        return new self(
            trim($directory, '/'),
            self::strings($rule, 'name', $where),
            self::strings($rule, 'notName', $where),
            array_map(static fn (string $p): string => trim($p, '/'), self::strings($rule, 'notPath', $where)),
        );
    }

    /** Whether this rule reads the staged `--no-dev` vendor directory rather than the app's. */
    public function isVendor(): bool
    {
        return $this->directory === 'vendor' || str_starts_with($this->directory, 'vendor/');
    }

    /** $relative is the file's path below `directory`, with `/` separators. */
    public function accepts(string $relative): bool
    {
        $base = basename($relative);

        foreach ($this->notPath as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) {
                return false;
            }
        }

        foreach ($this->notName as $pattern) {
            if (fnmatch($pattern, $base)) {
                return false;
            }
        }

        if ($this->name === []) {
            return true;
        }

        foreach ($this->name as $pattern) {
            if (fnmatch($pattern, $base)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $rule
     * @return list<string>
     */
    private static function strings(array $rule, string $key, string $where): array
    {
        $value = $rule[$key] ?? [];

        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value) || !array_is_list($value)) {
            throw new PackagerException("{$where}: '{$key}' must be a string or a list of strings.");
        }

        $out = [];

        foreach ($value as $item) {
            if (!\is_string($item) || $item === '') {
                throw new PackagerException("{$where}: '{$key}' must be a string or a list of strings.");
            }

            $out[] = $item;
        }

        return $out;
    }
}
