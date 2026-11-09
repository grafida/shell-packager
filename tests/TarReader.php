<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

/** Reads a .tar.gz back for assertions: name => [type, mode, mtime, data]. */
final class TarReader
{
    /** @return array<string, array{type: string, mode: int, mtime: int, uid: int, data: string}> */
    public static function read(string $path): array
    {
        $tar = gzdecode((string) file_get_contents($path));
        self::assertString($tar);
        $out = [];

        for ($at = 0; $at + 512 <= \strlen($tar);) {
            $header = substr($tar, $at, 512);

            if (trim($header, "\0") === '') {
                break;
            }

            $name   = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $size   = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            $full   = $prefix === '' ? $name : $prefix . '/' . $name;

            $out[$full] = [
                'type'  => $header[156],
                'mode'  => (int) octdec(trim(substr($header, 100, 8), "\0 ")),
                'mtime' => (int) octdec(trim(substr($header, 136, 12), "\0 ")),
                'uid'   => (int) octdec(trim(substr($header, 108, 8), "\0 ")),
                'data'  => substr($tar, $at + 512, $size),
            ];
            $at += 512 + (int) ceil($size / 512) * 512;
        }

        return $out;
    }

    /** @phpstan-assert string $value */
    private static function assertString(mixed $value): void
    {
        if (!\is_string($value)) {
            throw new \RuntimeException('Not a gzip file.');
        }
    }
}
