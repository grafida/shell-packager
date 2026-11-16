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
use Grafida\ShellPackager\Shell\Resolver;
use Grafida\ShellPackager\Shell\ShellConfig;
use Grafida\ShellPackager\Shell\ShellLock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShellLockTest extends TestCase
{
    private static function lock(): ShellLock
    {
        return Resolver::resolve(ShellConfig::fromArray(['version' => '^0.4'], __DIR__), [ResolverTest::release('0.4.2')]);
    }

    public function testRoundTripIsByteIdentical(): void
    {
        $json = self::lock()->toJson();

        self::assertSame($json, ShellLock::fromJson($json, 'x')->toJson());
        self::assertStringStartsWith("{\n    \"_readme\": ", $json);
        self::assertStringEndsWith("}\n", $json);
        self::assertStringContainsString('"url": "file:///x/0.4.2/grafida-shell-linux-x86_64"', $json, 'slashes unescaped');
        self::assertSame(
            ['_readme', 'content-hash', 'shell'],
            array_keys((array) json_decode($json, true))
        );

        $dir = Filesystem::tempDir('lock-test');

        try {
            self::assertNull(ShellLock::read($dir . '/shell-package.lock'));
            self::lock()->write($dir . '/shell-package.lock');
            self::assertSame($json, Filesystem::read($dir . '/shell-package.lock'));
            self::assertEquals(self::lock(), ShellLock::read($dir . '/shell-package.lock'));
        } finally {
            Filesystem::remove($dir);
        }
    }

    public function testTheLockSitsBesideTheConfiguration(): void
    {
        self::assertSame('/a/shell-package.lock', ShellLock::pathFor('/a/shell-package.json'));
        self::assertSame('/a/pkg.lock', ShellLock::pathFor('/a/pkg'));
    }

    /** @return array<string, array{\Closure(string): string}> */
    public static function broken(): array
    {
        return [
            'conflict markers' => [static fn (string $j): string => "<<<<<<< HEAD\n" . $j . "=======\n>>>>>>> other\n"],
            // The Windows shell is the last x86_64 entry: naming it aarch64 drops the x86_64 slot.
            'a missing slot'   => [static fn (string $j): string => substr_replace($j, '"aarch64": {', (int) strrpos($j, '"x86_64": {'), \strlen('"x86_64": {'))],
            'an extra slot'    => [static fn (string $j): string => preg_replace('/"linux": \{/', '"linux": {"extra": {},', $j, 1) ?? ''],
            'a bad hash'       => [static fn (string $j): string => preg_replace('/"sha256": "/', '"sha256": "XYZ', $j, 1) ?? ''],
            'no version'       => [static fn (string $j): string => preg_replace('/"version": "[^"]*",/', '', $j, 1) ?? ''],
        ];
    }

    /** @param \Closure(string): string $break */
    #[DataProvider('broken')]
    public function testABrokenLockIsRefused(\Closure $break): void
    {
        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('/some/where/shell-package.lock');
        ShellLock::fromJson($break(self::lock()->toJson()), '/some/where/shell-package.lock');
    }
}
