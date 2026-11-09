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
 * The CPU architectures, under the names the shell and phpmicro release assets use. `amd64` and
 * `arm64` (Debian's, Boson's) are accepted as spellings of the same two.
 */
enum Arch: string
{
    case X86_64 = 'x86_64';
    case Aarch64 = 'aarch64';

    public static function parse(string $name): self
    {
        return match (strtolower($name)) {
            'x86_64', 'amd64', 'x64' => self::X86_64,
            'aarch64', 'arm64' => self::Aarch64,
            default => throw new PackagerException("Unknown architecture '{$name}' (expected x86_64 or aarch64)."),
        };
    }

    /** The machine this runs on, or null if it is neither. */
    public static function host(): ?self
    {
        try {
            return self::parse(php_uname('m'));
        } catch (PackagerException) {
            return null;
        }
    }

    /** The name in Debian's and many tarballs' spelling, for output file names. */
    public function debian(): string
    {
        return match ($this) {
            self::X86_64 => 'amd64',
            self::Aarch64 => 'arm64',
        };
    }
}
