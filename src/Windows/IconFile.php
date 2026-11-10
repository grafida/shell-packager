<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Windows;

use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;

/**
 * A `.ico` file, turned into the resources an executable's icon is made of: one `RT_ICON` per
 * image (its bytes as they are in the file, a BMP DIB or a PNG) and one `RT_GROUP_ICON` listing
 * them. The group's entries are the file's directory entries with the 32-bit file offset replaced
 * by a 16-bit resource id (`GRPICONDIRENTRY`, 14 bytes, against `ICONDIRENTRY`'s 16).
 */
final readonly class IconFile
{
    /** @param list<array{header: string, data: string}> $images header: the first 12 bytes of each ICONDIRENTRY */
    public function __construct(public array $images)
    {
    }

    public static function read(string $path): self
    {
        return self::parse(Filesystem::read($path), $path);
    }

    public static function parse(string $data, string $what = 'the icon'): self
    {
        if (\strlen($data) < 6 || PeFile::u16($data, 0) !== 0 || PeFile::u16($data, 2) !== 1) {
            throw new PackagerException("{$what} is not a .ico file.");
        }

        $count  = PeFile::u16($data, 4);
        $images = [];

        if ($count === 0) {
            throw new PackagerException("{$what} holds no images.");
        }

        for ($i = 0; $i < $count; $i++) {
            $e = 6 + 16 * $i;

            if ($e + 16 > \strlen($data)) {
                throw new PackagerException("{$what}: its directory runs past the end of the file.");
            }

            $size   = PeFile::u32($data, $e + 8);
            $offset = PeFile::u32($data, $e + 12);

            if ($offset + $size > \strlen($data) || $size === 0) {
                throw new PackagerException("{$what}: image {$i} lies outside the file.");
            }

            $images[] = ['header' => substr($data, $e, 12), 'data' => substr($data, $offset, $size)];
        }

        return new self($images);
    }

    /**
     * The resources: `RT_ICON` ids from $firstId, and the `RT_GROUP_ICON` $groupId.
     *
     * @return list<ResourceEntry>
     */
    public function resources(int $groupId = 1, int $firstId = 1, int $lang = 0x0409): array
    {
        $out   = [];
        $group = pack('vvv', 0, 1, \count($this->images));

        foreach ($this->images as $i => $image) {
            $id     = $firstId + $i;
            $out[]  = new ResourceEntry(Resources::RT_ICON, $id, $lang, $image['data']);
            // width, height, colours, reserved, planes, bit count, bytes in resource; then the id.
            $group .= substr($image['header'], 0, 8) . pack('V', \strlen($image['data'])) . pack('v', $id);
        }

        $out[] = new ResourceEntry(Resources::RT_GROUP_ICON, $groupId, $lang, $group);

        return $out;
    }
}
