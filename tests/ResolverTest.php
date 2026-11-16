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
use Grafida\ShellPackager\Platform;
use Grafida\ShellPackager\Shell\Release;
use Grafida\ShellPackager\Shell\ReleaseAsset;
use Grafida\ShellPackager\Shell\Resolver;
use Grafida\ShellPackager\Shell\ShellAssets;
use Grafida\ShellPackager\Shell\ShellConfig;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase
{
    /** Every default asset name of a release, with a digest derived from the tag and the name. */
    public static function release(string $tag, bool $draft = false, ?string $without = null, ?string $noDigest = null): Release
    {
        $assets = [];

        foreach (ShellConfig::fromArray(['version' => '*'], __DIR__)->assets as $components) {
            foreach ($components as $byArch) {
                foreach ($byArch as $name) {
                    if ($name === $without) {
                        continue;
                    }

                    $assets[$name] = new ReleaseAsset($name, "file:///x/{$tag}/{$name}", $name === $noDigest ? null : hash('sha256', "{$tag}/{$name}"));
                }
            }
        }

        return new Release($tag, $draft, $assets);
    }

    /** @return list<Release> */
    private static function releases(): array
    {
        return [
            self::release('0.4.0'),
            self::release('0.4.2'),
            self::release('v0.4.1'),
            self::release('0.4.3-beta1'),
            self::release('0.5.0'),
            self::release('nightly'),
            self::release('0.4.9', draft: true),
        ];
    }

    private static function shell(string $constraint): ShellConfig
    {
        return ShellConfig::fromArray(['version' => $constraint], __DIR__);
    }

    public function testMinimumStability(): void
    {
        self::assertSame('stable', Resolver::minimumStability('^0.4'));
        self::assertSame('beta', Resolver::minimumStability('^0.4@beta'));
        self::assertSame('beta', Resolver::minimumStability('1.0.0-beta1'));
        self::assertSame('alpha', Resolver::minimumStability('>=1.0.0-alpha2 <2'));
        self::assertSame('dev', Resolver::minimumStability('^1.0@RC || ^2.0@dev'));
        self::assertSame('RC', Resolver::minimumStability('^1.0@rc'));
        self::assertSame('stable', Resolver::minimumStability('~1.2.3'));
    }

    public function testPicksTheHighestStableMatch(): void
    {
        self::assertSame('0.4.2', Resolver::resolve(self::shell('^0.4'), self::releases())->version);
        self::assertSame('0.4.3-beta1', Resolver::resolve(self::shell('^0.4@beta'), self::releases())->version);
        self::assertSame('0.5.0', Resolver::resolve(self::shell('*'), self::releases())->version);

        $exact = Resolver::resolve(self::shell('0.4.1'), self::releases());
        self::assertSame('0.4.1', $exact->version);
        self::assertSame('v0.4.1', $exact->tag);

        // The draft is never picked, even when it is the only match.
        $this->expectException(PackagerException::class);
        Resolver::resolve(self::shell('0.4.9'), self::releases());
    }

    public function testTheLockNamesEverySlot(): void
    {
        $shell = self::shell('^0.4');
        $lock  = Resolver::resolve($shell, self::releases());

        self::assertSame('grafida/shell', $lock->repository);
        self::assertSame('^0.4', $lock->constraint);
        self::assertSame('0.4.2', $lock->tag);
        self::assertSame($shell->contentHash(), $lock->contentHash);

        $slots = [];

        foreach ($lock->files as $platform => $components) {
            foreach ($components as $component => $byArch) {
                $slots[$platform][$component] = array_keys($byArch);
            }
        }

        self::assertSame(ShellAssets::SLOTS, $slots);

        $file = $lock->file(Platform::Macos, 'shell', Arch::Aarch64);
        self::assertSame('grafida-shell-macos-arm64', $file->name);
        self::assertSame('file:///x/0.4.2/grafida-shell-macos-arm64', $file->url);
        self::assertSame(hash('sha256', '0.4.2/grafida-shell-macos-arm64'), $file->sha256);
    }

    public function testNothingMatchingIsAnErrorListingWhatThereIs(): void
    {
        try {
            Resolver::resolve(self::shell('^9'), self::releases());
            self::fail('No release satisfies ^9.');
        } catch (PackagerException $e) {
            self::assertStringContainsString('^9', $e->getMessage());
            self::assertStringContainsString('grafida/shell', $e->getMessage());
            self::assertStringContainsString('0.5.0', $e->getMessage());
            self::assertStringNotContainsString('0.4.9', $e->getMessage(), 'a draft is not offered');
        }
    }

    public function testAMissingAssetOrDigestIsAnError(): void
    {
        $missing = [self::release('0.4.0'), self::release('0.4.2', without: 'grafida-shell-windows-x86_64.exe')];

        try {
            Resolver::resolve(self::shell('^0.4'), $missing);
            self::fail('0.4.2 lacks the Windows shell.');
        } catch (PackagerException $e) {
            self::assertStringContainsString('0.4.2', $e->getMessage());
            self::assertStringContainsString('grafida-shell-windows-x86_64.exe', $e->getMessage());
        }

        $this->expectException(PackagerException::class);
        $this->expectExceptionMessageMatches('/grafida-launcher-linux-x86_64.*digest|digest.*grafida-launcher-linux-x86_64/s');
        Resolver::resolve(self::shell('^0.4'), [self::release('0.4.2', noDigest: 'grafida-launcher-linux-x86_64')]);
    }
}
