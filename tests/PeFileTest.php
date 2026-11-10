<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Windows\PeFile;
use PHPUnit\Framework\TestCase;

final class PeFileTest extends TestCase
{
    public function testTheHeadersAndSectionsAreRead(): void
    {
        $pe = PeFile::parse(PeBuilder::bytes());

        self::assertTrue($pe->isAmd64());
        self::assertTrue($pe->is64);
        self::assertSame(PeFile::SUBSYSTEM_GUI, $pe->subsystem);
        self::assertSame(['.text', '.rsrc', '.reloc'], array_map(static fn ($s): string => $s->name, $pe->sections));
        self::assertSame(0x200, $pe->fileAlignment);
        self::assertSame(\strlen($pe->data), $pe->imageEnd());
        self::assertSame(0, $pe->overlayBytes());
        self::assertFalse($pe->isSigned());
        self::assertNotNull($pe->resourceData());
    }

    public function testPe32IsReadToo(): void
    {
        // NSIS writes installers and uninstallers from x86 stubs, and they are signed too.
        $pe = PeFile::parse(PeBuilder::bytes(machine: 0x14c, pe32: true));

        self::assertFalse($pe->is64);
        self::assertFalse($pe->isAmd64());
        self::assertSame($pe->optionalHeader() + 96, $pe->dataDirectories());
        self::assertNotNull($pe->resourceData());
    }

    public function testAnOverlayIsSeenAndTheCertificateTableIsNot(): void
    {
        self::assertSame(1000, PeFile::parse(PeBuilder::bytes(overlay: str_repeat('P', 1000)))->overlayBytes());

        $signed = PeFile::parse(PeBuilder::bytes(certificate: str_repeat('C', 800)));
        self::assertTrue($signed->isSigned());
        self::assertSame(0, $signed->overlayBytes());

        // An SFX with its PHAR appended, then signed: the PHAR is still an overlay.
        self::assertSame(1000, PeFile::parse(PeBuilder::bytes(overlay: str_repeat('P', 1000), certificate: str_repeat('C', 800)))->overlayBytes());
    }

    public function testRvasMapToFileOffsets(): void
    {
        $pe   = PeFile::parse(PeBuilder::bytes());
        $text = $pe->sections[0];

        self::assertSame($text->rawPointer + 4, $pe->offsetOf($text->virtualAddress + 4));
        self::assertNull($pe->offsetOf(0x7fff0000));
        self::assertSame(0, $pe->sectionAt($text->virtualAddress));
    }

    public function testTheChecksumIsTheImageHelpers(): void
    {
        $data = PeBuilder::bytes();
        $at   = PeFile::parse($data)->optionalHeader() + 64;

        // The reference: 16-bit words with end-around carry, the field itself left out, plus the length.
        $sum = 0;

        for ($i = 0; $i < \strlen($data); $i += 2) {
            if ($i === $at || $i === $at + 2) {
                continue;
            }

            $sum += PeFile::u16($data . "\0", $i);
            $sum  = ($sum & 0xffff) + ($sum >> 16);
        }

        self::assertSame(($sum & 0xffff) + \strlen($data), PeFile::checksum($data, $at));
        // The field's own value does not matter.
        self::assertSame(PeFile::checksum($data, $at), PeFile::checksum(substr_replace($data, "\xff\xff\xff\xff", $at, 4), $at));
    }

    public function testSomethingElseIsRefused(): void
    {
        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('no MZ header');
        PeFile::parse("#!/bin/sh\necho not a binary\n" . str_repeat(' ', 80));
    }

    public function testTheRealPinsAreWhatThePackagerExpects(): void
    {
        $found = false;

        foreach (['SHELL_PACKAGER_TEST_WINDOWS_SHELL', 'SHELL_PACKAGER_TEST_WINDOWS_SFX'] as $env) {
            $path = getenv($env);

            if (!\is_string($path) || $path === '') {
                continue;
            }

            $found = true;
            $pe    = PeFile::read($path);
            self::assertTrue($pe->isAmd64(), $env);
            self::assertSame(PeFile::SUBSYSTEM_GUI, $pe->subsystem, "{$env}: a console subsystem would open a window");
            self::assertSame(0, $pe->overlayBytes(), "{$env}: nothing may be appended to it");
            self::assertFalse($pe->isSigned(), $env);

            foreach ($pe->imports() as $dll) {
                self::assertDoesNotMatchRegularExpression('/^(vcruntime|msvcp|api-ms-win-crt-|ucrtbase)/i', $dll, "{$env} imports the Visual C++ runtime");
            }
        }

        if (!$found) {
            self::markTestSkipped('Set SHELL_PACKAGER_TEST_WINDOWS_SHELL / _SFX (CI fetches the pins) to check the real binaries.');
        }
    }
}
