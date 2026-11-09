<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Phar;

use Grafida\ShellPackager\PackagerException;

/**
 * phpmicro's extra-INI block: `fd f6 69 e6`, the ini text's length as a 4-byte **big-endian**
 * integer, then the ini text, placed between the SFX and the payload. phpmicro reads it at start-up,
 * before any PHP runs, so it can carry settings `ini_set()` cannot (`PHP_INI_SYSTEM`, such as
 * `opcache.enable_cli`). In sibling mode the block leads the `.phar` file instead.
 *
 * ⚠️ phpmicro silently ignores a block whose magic or length is wrong, and then runs its bytes as
 * the start of the script: they are written to stdout as inline HTML, which for a shell worker is
 * a corrupt frame before `ready`. So the packager also checks the block by running it
 * ({@see WorkerCheck}).
 */
final class IniBlock
{
    public const MAGIC = "\xfd\xf6\x69\xe6";

    /**
     * @param array<string, scalar> $settings
     */
    public static function encode(array $settings): string
    {
        if ($settings === []) {
            return '';
        }

        $text = '';

        foreach ($settings as $key => $value) {
            if (preg_match('/^[A-Za-z0-9_.\-]+\z/', $key) !== 1) {
                throw new PackagerException("Invalid ini setting name '{$key}'.");
            }

            $text .= $key . '=' . self::value($value) . "\n";
        }

        return self::MAGIC . pack('N', \strlen($text)) . $text;
    }

    /**
     * Reads a block back: the settings text and the number of bytes the block takes (0 if there is
     * none). The inverse of {@see encode()}, for tests and for inspecting a built payload.
     *
     * @return array{text: string, length: int}
     */
    public static function decode(string $payload): array
    {
        if (\strlen($payload) < 8 || !str_starts_with($payload, self::MAGIC)) {
            return ['text' => '', 'length' => 0];
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($payload, 4, 4));
        $length   = $unpacked[1];

        return ['text' => substr($payload, 8, $length), 'length' => 8 + $length];
    }

    private static function value(bool|int|float|string $value): string
    {
        return match (true) {
            \is_bool($value) => $value ? '1' : '0',
            \is_int($value), \is_float($value) => (string) $value,
            preg_match('/^[A-Za-z0-9_.\/+\-]*\z/', $value) === 1 => $value,
            // Single-quoted ini strings are raw (no `${…}` expansion, no escapes), so the one thing
            // they cannot carry is a single quote, or a line break.
            preg_match("/['\r\n]/", $value) === 1 => throw new PackagerException(
                'An ini value cannot contain a single quote or a line break: ' . json_encode($value)
            ),
            default => "'" . $value . "'",
        };
    }
}
