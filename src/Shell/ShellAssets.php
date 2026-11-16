<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Shell;

use Grafida\ShellPackager\Arch;
use Grafida\ShellPackager\PackagerException;

/**
 * The shell's files: which ones a release carries (the slots), and what `grafida/shell` calls
 * them. A slot is a platform, a component and an architecture; there are seven, and every shell
 * release carries all of them, so a lock always names all seven.
 *
 * A name (or, under `shell.local`, a path) is given like a runtime's `url`: a string in which
 * `{arch}` stands for `x86_64`/`aarch64`, or an object mapping each architecture to its own. The
 * object covers macOS, whose arm64 file is not called `aarch64`.
 */
final class ShellAssets
{
    /** platform => component => architectures, all sorted */
    public const array SLOTS = [
        'linux'   => ['launcher' => ['aarch64', 'x86_64'], 'shell' => ['aarch64', 'x86_64']],
        'macos'   => ['shell' => ['aarch64', 'x86_64']],
        'windows' => ['shell' => ['x86_64']],
    ];

    /** What grafida/shell publishes. */
    public const array DEFAULTS = [
        'linux'   => ['launcher' => 'grafida-launcher-linux-{arch}', 'shell' => 'grafida-shell-linux-{arch}'],
        'macos'   => ['shell' => ['aarch64' => 'grafida-shell-macos-arm64', 'x86_64' => 'grafida-shell-macos-x86_64']],
        'windows' => ['shell' => 'grafida-shell-windows-{arch}.exe'],
    ];

    /**
     * Validates a `{platform: {component: string|{arch: string}}}` map against the slots and
     * expands it to platform => component => arch => value. Without $partial, an object must name
     * every architecture of its slot; with it (local files), any of them.
     *
     * @param array<mixed> $raw
     * @return array<string, array<string, array<string, string>>>
     */
    public static function expand(array $raw, string $key, bool $partial): array
    {
        $out = [];

        foreach ($raw as $platform => $components) {
            $platform = (string) $platform;

            if (!isset(self::SLOTS[$platform])) {
                throw new PackagerException("{$key}.{$platform}: the shell has no such platform (" . implode(', ', array_keys(self::SLOTS)) . ').');
            }

            if (!\is_array($components)) {
                throw new PackagerException("{$key}.{$platform} must be an object of components.");
            }

            foreach ($components as $component => $value) {
                $component = (string) $component;
                $arches    = self::SLOTS[$platform][$component] ?? null;

                if ($arches === null) {
                    throw new PackagerException("{$key}.{$platform}.{$component}: the shell has no such file on {$platform} (" . implode(', ', array_keys(self::SLOTS[$platform])) . ').');
                }

                $out[$platform][$component] = self::one($value, $arches, "{$key}.{$platform}.{$component}", $partial);
            }
        }

        return $out;
    }

    /**
     * @param list<string> $arches
     * @return array<string, string>
     */
    private static function one(mixed $value, array $arches, string $key, bool $partial): array
    {
        if (\is_string($value) && $value !== '') {
            $out = [];

            foreach ($arches as $arch) {
                $out[$arch] = str_replace('{arch}', $arch, $value);
            }

            return $out;
        }

        if (!\is_array($value) || $value === []) {
            throw new PackagerException("{$key} must be a string (with {arch}) or map each architecture to one.");
        }

        $out = [];

        foreach ($value as $arch => $one) {
            $arch = Arch::parse((string) $arch)->value;

            if (!\in_array($arch, $arches, true)) {
                throw new PackagerException("{$key}.{$arch}: there is no {$arch} file for this slot (" . implode(', ', $arches) . ').');
            }

            if (!\is_string($one) || $one === '') {
                throw new PackagerException("{$key}.{$arch} must be a string.");
            }

            $out[$arch] = str_replace('{arch}', $arch, $one);
        }

        if (!$partial) {
            foreach ($arches as $arch) {
                if (!isset($out[$arch])) {
                    throw new PackagerException("{$key}.{$arch} is missing: name every architecture, or use one string with {arch}.");
                }
            }
        }

        ksort($out);

        return $out;
    }
}
