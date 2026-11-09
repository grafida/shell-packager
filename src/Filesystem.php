<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager;

/** The few filesystem operations the packager needs, failing loudly. */
final class Filesystem
{
    public static function read(string $path): string
    {
        $data = @file_get_contents($path);

        if ($data === false) {
            throw new PackagerException("Cannot read {$path}.");
        }

        return $data;
    }

    public static function write(string $path, string $data): void
    {
        self::mkdir(\dirname($path));

        if (@file_put_contents($path, $data) !== \strlen($data)) {
            throw new PackagerException("Cannot write {$path}.");
        }
    }

    public static function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new PackagerException("Cannot create {$dir}.");
        }
    }

    public static function tempDir(string $purpose): string
    {
        $dir = sys_get_temp_dir() . '/shell-packager-' . $purpose . '-' . bin2hex(random_bytes(6));
        self::mkdir($dir);

        return $dir;
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? \sprintf('%.1f MiB', $bytes / 1048576) : \sprintf('%.1f KiB', $bytes / 1024);
    }
}
