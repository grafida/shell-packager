<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Archive;

use Grafida\ShellPackager\PackagerException;

/**
 * A minimal, deterministic `.tar.gz` writer (POSIX ustar), streamed to disk so a package never has
 * to fit in memory. Owner and group are 0 with empty names, every entry has the same mtime, and
 * entries appear in the order they are added; the gzip header carries no time or name. It exists
 * because neither `PharData` (no control over modes and times) nor BSD tar on macOS (no `--sort`,
 * `--mtime`, `--owner`) can produce a reproducible archive, and Linux packages are built on macOS
 * as well.
 */
final class TarWriter
{
    private const BLOCK = 512;

    /** @var resource */
    private $out;

    private \DeflateContext $deflate;

    public function __construct(string $path, private readonly int $mtime)
    {
        $out = fopen($path, 'wb');

        if ($out === false) {
            throw new PackagerException("Cannot write {$path}.");
        }

        $this->out     = $out;
        $deflate       = deflate_init(\ZLIB_ENCODING_GZIP, ['level' => 9]);

        if ($deflate === false) {
            throw new PackagerException('Cannot start gzip compression.');
        }

        $this->deflate = $deflate;
    }

    public function directory(string $name, int $mode = 0o755): void
    {
        $this->emit($this->header(rtrim($name, '/') . '/', 0, $mode, '5'));
    }

    public function data(string $name, string $data, int $mode = 0o644): void
    {
        $this->emit($this->header($name, \strlen($data), $mode, '0'));
        $this->emit($data);
        $this->pad(\strlen($data));
    }

    public function file(string $name, string $source, int $mode = 0o644): void
    {
        $size = filesize($source);
        $in   = fopen($source, 'rb');

        if ($size === false || $in === false) {
            throw new PackagerException("Cannot read {$source}.");
        }

        $this->emit($this->header($name, $size, $mode, '0'));

        while (!feof($in)) {
            $chunk = fread($in, 1048576);

            if ($chunk === false) {
                throw new PackagerException("Cannot read {$source}.");
            }

            $this->emit($chunk);
        }

        fclose($in);
        $this->pad($size);
    }

    public function close(): void
    {
        $this->emit(str_repeat("\0", self::BLOCK * 2));
        fwrite($this->out, (string) deflate_add($this->deflate, '', \ZLIB_FINISH));
        fclose($this->out);
    }

    private function header(string $name, int $size, int $mode, string $type): string
    {
        [$prefix, $short] = self::split($name);

        $header = pack(
            'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $short,
            \sprintf('%07o', $mode),
            \sprintf('%07o', 0),
            \sprintf('%07o', 0),
            \sprintf('%011o', $size),
            \sprintf('%011o', $this->mtime),
            str_repeat(' ', 8),
            $type,
            '',
            'ustar',
            '00',
            '',
            '',
            '',
            '',
            $prefix,
            ''
        );

        $checksum = array_sum(array_map(\ord(...), str_split($header)));

        return substr_replace($header, \sprintf('%06o', $checksum) . "\0 ", 148, 8);
    }

    /**
     * ustar keeps names over 100 bytes as a prefix (≤ 155) plus a name (≤ 100), split at a `/`.
     *
     * @return array{string, string}
     */
    private static function split(string $name): array
    {
        if (\strlen($name) <= 100) {
            return ['', $name];
        }

        $slash = strrpos(substr($name, 0, 156), '/');

        while ($slash !== false && $slash > 0) {
            if (\strlen($name) - $slash - 1 <= 100) {
                return [substr($name, 0, $slash), substr($name, $slash + 1)];
            }

            $slash = strrpos(substr($name, 0, $slash), '/');
        }

        throw new PackagerException("Path too long for a tar archive: {$name}");
    }

    private function pad(int $size): void
    {
        $rest = $size % self::BLOCK;

        if ($rest !== 0) {
            $this->emit(str_repeat("\0", self::BLOCK - $rest));
        }
    }

    private function emit(string $bytes): void
    {
        $compressed = deflate_add($this->deflate, $bytes, \ZLIB_NO_FLUSH);

        if ($compressed === false || fwrite($this->out, $compressed) === false) {
            throw new PackagerException('Cannot write the archive.');
        }
    }
}
