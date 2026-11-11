<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\Arch;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Runtime;
use PHPUnit\Framework\TestCase;

/** A runtime's download URL: one template, or one per architecture (grafida/grafida#92). */
final class RuntimeTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testOneTemplateForEveryArchitecture(): void
    {
        $r = Runtime::fromArray('sfx', ['url' => 'https://x/sfx-1/macos-{arch}.sfx', 'sha256' => ['arm64' => self::A, 'x86_64' => self::B]]);

        self::assertSame('https://x/sfx-1/macos-aarch64.sfx', $r->url(Arch::Aarch64));
        self::assertSame('https://x/sfx-1/macos-x86_64.sfx', $r->url(Arch::X86_64));
        self::assertSame(self::A, $r->pin(Arch::Aarch64));
    }

    public function testAUrlPerArchitecture(): void
    {
        // The arm64 and x86_64 pins of one app from different releases.
        $r = Runtime::fromArray('shell', [
            'url'    => ['arm64' => 'https://x/0.2.0/grafida-shell-macos-arm64', 'amd64' => 'https://x/0.4.0/grafida-shell-macos-{arch}'],
            'sha256' => ['aarch64' => self::A, 'x86_64' => self::B],
        ]);

        self::assertSame('https://x/0.2.0/grafida-shell-macos-arm64', $r->url(Arch::Aarch64));
        self::assertSame('https://x/0.4.0/grafida-shell-macos-x86_64', $r->url(Arch::X86_64));
    }

    public function testAnArchitectureWithNoUrlIsNamed(): void
    {
        $r = Runtime::fromArray('shell', ['url' => ['aarch64' => 'https://x/a'], 'sha256' => ['aarch64' => self::A]]);

        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('runtimes.shell.url.x86_64');
        $r->url(Arch::X86_64);
    }

    public function testABadUrlMapIsRefused(): void
    {
        foreach ([['aarch64' => ''], [], ['sparc' => 'https://x']] as $url) {
            try {
                Runtime::fromArray('shell', ['url' => $url, 'sha256' => ['aarch64' => self::A]]);
                self::fail('accepted ' . json_encode($url));
            } catch (PackagerException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
