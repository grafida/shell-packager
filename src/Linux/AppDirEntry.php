<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Linux;

/**
 * One entry of an AppImage's AppDir, described rather than written, so that {@see Squashfs} can
 * stage it as a directory or stream it as a tar with the same names and modes.
 * `content` is a source path (FILE), the bytes (DATA), a link target (SYMLINK), or empty (DIRECTORY).
 */
final readonly class AppDirEntry
{
    public const DIRECTORY = 'dir';
    public const FILE      = 'file';
    public const DATA      = 'data';
    public const SYMLINK   = 'link';

    /** @param self::DIRECTORY|self::FILE|self::DATA|self::SYMLINK $type */
    private function __construct(public string $name, public string $type, public string $content, public int $mode)
    {
    }

    public static function directory(string $name): self
    {
        return new self($name, self::DIRECTORY, '', 0o755);
    }

    public static function file(string $name, string $source, int $mode): self
    {
        return new self($name, self::FILE, $source, $mode);
    }

    public static function data(string $name, string $data, int $mode): self
    {
        return new self($name, self::DATA, $data, $mode);
    }

    public static function symlink(string $name, string $target): self
    {
        return new self($name, self::SYMLINK, $target, 0o777);
    }
}
