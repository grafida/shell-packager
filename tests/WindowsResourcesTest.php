<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Windows\IconFile;
use Grafida\ShellPackager\Windows\PeFile;
use Grafida\ShellPackager\Windows\ResourceEntry;
use Grafida\ShellPackager\Windows\Resources;
use Grafida\ShellPackager\Windows\ResourceWriter;
use Grafida\ShellPackager\Windows\VersionInfo;
use PHPUnit\Framework\TestCase;

/** The resource tree, icons, version info, and writing them into an executable. */
final class WindowsResourcesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Filesystem::tempDir('pe-test');
    }

    protected function tearDown(): void
    {
        Filesystem::remove($this->dir);
    }

    public function testATreeRoundTripsWithNamesFirst(): void
    {
        $tree = new Resources([
            new ResourceEntry(Resources::RT_MANIFEST, 1, 0x0409, 'manifest'),
            new ResourceEntry(Resources::RT_GROUP_ICON, 'MAINICON', 0x0409, 'group'),
            new ResourceEntry(Resources::RT_ICON, 2, 0x0409, 'two'),
            new ResourceEntry(Resources::RT_ICON, 1, 0x0409, 'one'),
            new ResourceEntry(Resources::RT_ICON, 1, 0x0408, 'one, in Greek'),
            new ResourceEntry('CUSTOM', 'Δεδομένα', 0, "\0binary\xff"),
        ]);
        $bytes = $tree->serialize(0x5000);
        $back  = Resources::parse($bytes, 0x5000);

        $key = static fn (ResourceEntry $e): string => json_encode([$e->type, $e->name, $e->lang, base64_encode($e->data)], \JSON_THROW_ON_ERROR);
        $a   = array_map($key, $tree->entries());
        $b   = array_map($key, $back->entries());
        sort($a);
        sort($b);
        self::assertSame($a, $b);

        // The order the loader binary-searches: the named type first, then ids ascending.
        self::assertSame(['CUSTOM', 3, 3, 3, 14, 24], array_map(static fn ($e) => $e->type, $back->entries()));
        // Every data entry is an RVA inside the tree, 8-byte aligned.
        self::assertSame(0, \strlen($bytes) % 8);
    }

    public function testPutReplacesAndRemoveTypeRemoves(): void
    {
        $tree = new Resources([new ResourceEntry(Resources::RT_ICON, 1, 0, 'a'), new ResourceEntry(Resources::RT_VERSION, 1, 0, 'v')]);
        $tree->put(new ResourceEntry(Resources::RT_ICON, 1, 0, 'b'));
        self::assertSame('b', $tree->ofType(Resources::RT_ICON)[0]->data);
        self::assertCount(2, $tree->entries());
        $tree->removeType(Resources::RT_ICON);
        self::assertSame([Resources::RT_VERSION], array_map(static fn ($e) => $e->type, $tree->entries()));
    }

    public function testAnIconBecomesIconsAndAGroup(): void
    {
        $png  = "\x89PNG\r\n\x1a\n" . str_repeat('p', 40);
        $bmp  = pack('V', 40) . str_repeat('b', 60);
        $ico  = pack('vvv', 0, 1, 2)
            . pack('CCCCvvVV', 0, 0, 0, 0, 1, 32, \strlen($png), 6 + 32)
            . pack('CCCCvvVV', 16, 16, 0, 0, 1, 32, \strlen($bmp), 6 + 32 + \strlen($png))
            . $png . $bmp;
        $resources = IconFile::parse($ico)->resources();

        self::assertCount(3, $resources);
        self::assertSame([Resources::RT_ICON, 1, $png], [$resources[0]->type, $resources[0]->name, $resources[0]->data]);
        self::assertSame([Resources::RT_ICON, 2, $bmp], [$resources[1]->type, $resources[1]->name, $resources[1]->data]);

        $group = $resources[2];
        self::assertSame(Resources::RT_GROUP_ICON, $group->type);
        self::assertSame(6 + 2 * 14, \strlen($group->data));
        // GRPICONDIRENTRY: the 8 header bytes, the size, then the RT_ICON id (not a file offset).
        self::assertSame(\strlen($png), PeFile::u32($group->data, 6 + 8));
        self::assertSame(1, PeFile::u16($group->data, 6 + 12));
        self::assertSame(16, \ord($group->data[6 + 14]));
        self::assertSame(2, PeFile::u16($group->data, 6 + 14 + 12));
    }

    public function testABadIconIsRefused(): void
    {
        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('outside the file');
        IconFile::parse(pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 16, 16, 0, 0, 1, 32, 999, 22));
    }

    public function testVersionsBecomeFourParts(): void
    {
        self::assertSame([1, 0, 0, 1000], VersionInfo::numeric('1.0'));
        self::assertSame([1, 2, 3, 1000], VersionInfo::numeric('1.2.3'));
        self::assertSame([1, 2, 3, 1000], VersionInfo::numeric('1.2.3.4'));
        self::assertSame([1, 2, 3, 0], VersionInfo::numeric('1.2.3-alpha1'));
        self::assertSame([1, 2, 3, 13], VersionInfo::numeric('1.2.3-beta4'));
        self::assertSame([2, 0, 0, 901], VersionInfo::numeric('2.0rc2'));
        self::assertSame([0, 9, 0, 0], VersionInfo::numeric('0.9-dev'));
        // A pre-release sorts below its release.
        self::assertLessThan(VersionInfo::numeric('1.2.3')[3], VersionInfo::numeric('1.2.3-rc99')[3]);
    }

    public function testAVersionThatIsNotOneIsRefused(): void
    {
        $this->expectExceptionMessage('unrecognised suffix');
        VersionInfo::numeric('1.0-preview');
    }

    public function testAVersionResourceRoundTrips(): void
    {
        $info = new VersionInfo([1, 2, 3, 13], [1, 2, 3, 1000], [
            'CompanyName'     => 'Ακμή & Co',
            'FileDescription' => 'Packager Test',
            'FileVersion'     => '1.2.3-beta4',
            'Comments'        => '',
        ]);
        $back = VersionInfo::decode($info->bytes());

        self::assertSame([1, 2, 3, 13], $back['fileVersion']);
        self::assertSame([1, 2, 3, 1000], $back['productVersion']);
        // An empty string is left out, as rc.exe would.
        self::assertSame(['CompanyName' => 'Ακμή & Co', 'FileDescription' => 'Packager Test', 'FileVersion' => '1.2.3-beta4'], $back['strings']);
        self::assertSame(0, \strlen($info->bytes()) % 2);
    }

    public function testTheWriterRebuildsTheResourcesAndMovesTheRelocations(): void
    {
        $path = $this->file(PeBuilder::bytes());
        $old  = PeFile::read($path);
        ResourceWriter::apply($path, IconFile::parse($this->ico()), new VersionInfo([1, 0, 0, 1000], [1, 0, 0, 1000], ['FileDescription' => 'Shell']));
        $new = PeFile::read($path);

        self::assertSame(0, $new->overlayBytes());
        self::assertSame(\strlen($new->data), $new->imageEnd());
        self::assertSame(['.text', '.rsrc', '.reloc'], array_map(static fn ($s): string => $s->name, $new->sections));

        // The manifest stays; the icon and version are added.
        $tree = ResourceWriter::existing($new);
        self::assertSame(PeBuilder::MANIFEST, $tree->ofType(Resources::RT_MANIFEST)[0]->data);
        self::assertCount(1, $tree->ofType(Resources::RT_GROUP_ICON));
        self::assertSame('Shell', VersionInfo::decode($tree->ofType(Resources::RT_VERSION)[0]->data)['strings']['FileDescription']);

        // .reloc moved behind the bigger .rsrc, byte for byte, and its directory with it.
        $reloc = $new->sections[2];
        self::assertGreaterThan($old->sections[2]->virtualAddress, $reloc->virtualAddress);
        self::assertSame(0, $reloc->virtualAddress % $new->sectionAlignment);
        self::assertSame([$reloc->virtualAddress, \strlen(PeBuilder::RELOC)], $new->directories[PeFile::DIR_BASERELOC]);
        self::assertSame(PeBuilder::RELOC, substr($new->data, $reloc->rawPointer, \strlen(PeBuilder::RELOC)));

        // The headers follow: the image size, the checksum.
        self::assertSame($reloc->virtualAddress + 0x1000, PeFile::u32($new->data, $new->optionalHeader() + 56));
        self::assertSame(PeFile::checksum($new->data, $new->optionalHeader() + 64), PeFile::u32($new->data, $new->optionalHeader() + 64));
    }

    public function testTheWriterWorksWhenTheResourcesAreLast(): void
    {
        $path = $this->file(PeBuilder::bytes(withReloc: false));
        ResourceWriter::apply($path, null, new VersionInfo([2, 0, 0, 1000], [2, 0, 0, 1000], ['ProductName' => 'P']));
        $tree = ResourceWriter::existing(PeFile::read($path));

        self::assertSame([2, 0, 0, 1000], VersionInfo::decode($tree->ofType(Resources::RT_VERSION)[0]->data)['fileVersion']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'an SFX with its payload appended' => [PeBuilder::bytes(overlay: str_repeat('P', 600)), 'still appended'];
        yield 'a signed file' => [PeBuilder::bytes(certificate: str_repeat('C', 600)), 'signed already'];
        yield 'a section between the resources and the relocations' => [PeBuilder::bytes(sectionAfterResources: true), 'not supported'];
        yield 'an x86 executable' => [PeBuilder::bytes(machine: 0x14c, pe32: true), 'not an x86_64'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testTheWriterRefuses(string $bytes, string $message): void
    {
        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage($message);
        ResourceWriter::apply($this->file($bytes), null, new VersionInfo([1, 0, 0, 0], [1, 0, 0, 0], []));
    }

    public function testTheRealPinsTakeResources(): void
    {
        $found = false;

        foreach (['SHELL_PACKAGER_TEST_WINDOWS_SHELL', 'SHELL_PACKAGER_TEST_WINDOWS_SFX'] as $env) {
            $source = getenv($env);

            if (!\is_string($source) || $source === '') {
                continue;
            }

            $found = true;
            $path  = $this->dir . '/' . basename($source);
            copy($source, $path);
            $before = ResourceWriter::existing(PeFile::read($path))->ofType(Resources::RT_MANIFEST);
            ResourceWriter::apply($path, IconFile::parse($this->ico()), new VersionInfo([1, 0, 0, 1000], [1, 0, 0, 1000], ['FileDescription' => $env]));
            $pe   = PeFile::read($path);
            $tree = ResourceWriter::existing($pe);

            self::assertSame(0, $pe->overlayBytes(), $env);
            self::assertEquals($before, $tree->ofType(Resources::RT_MANIFEST), "{$env}: the application manifest must survive");
            self::assertSame($env, VersionInfo::decode($tree->ofType(Resources::RT_VERSION)[0]->data)['strings']['FileDescription']);
            self::assertCount(1, $tree->ofType(Resources::RT_GROUP_ICON), "{$env}: only our icon group");
        }

        if (!$found) {
            self::markTestSkipped('Set SHELL_PACKAGER_TEST_WINDOWS_SHELL / _SFX (CI fetches the pins) to check the real binaries.');
        }
    }

    private function ico(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/app/icons/app.ico');
    }

    private function file(string $bytes): string
    {
        $path = $this->dir . '/bin-' . bin2hex(random_bytes(4)) . '.exe';
        Filesystem::write($path, $bytes);

        return $path;
    }
}
