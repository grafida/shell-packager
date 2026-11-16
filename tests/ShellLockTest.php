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
use Grafida\ShellPackager\Shell\LockedFile;
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
            // The name, version and repository become cache paths: none may leave the cache.
            'a path as a name' => [static fn (string $j): string => preg_replace('/"name": "[^"]*"/', '"name": "../../evil"', $j, 1) ?? ''],
            'a path as version' => [static fn (string $j): string => preg_replace('/"version": "[^"]*"/', '"version": "../.."', $j, 1) ?? ''],
            'a bad repository' => [static fn (string $j): string => preg_replace('/"repository": "[^"]*"/', '"repository": "../x/y"', $j, 1) ?? ''],
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

    /** A lock as GitHub would have it: browser_download_url of the configured repository. */
    private static function githubLock(string $version = '0.4.2', string $tag = '0.4.2'): ShellLock
    {
        $lock  = self::lock();
        $files = [];

        foreach ($lock->files as $platform => $components) {
            foreach ($components as $component => $byArch) {
                foreach ($byArch as $arch => $file) {
                    $files[$platform][$component][$arch] = new LockedFile($file->name, "https://github.com/grafida/shell/releases/download/{$tag}/{$file->name}", $file->sha256);
                }
            }
        }

        return new ShellLock($lock->contentHash, $lock->repository, $lock->constraint, $version, $tag, $files);
    }

    public function testALockMustAgreeWithTheConfigurationItClaims(): void
    {
        $shell = ShellConfig::fromArray(['version' => '^0.4'], __DIR__);
        self::githubLock()->verify($shell, 'x.lock');
        $this->addToAssertionCount(1);

        $tampered = [
            'another repository' => static fn (string $j): string => str_replace('"repository": "grafida/shell"', '"repository": "evil/shell"', $j),
            'another name'       => static fn (string $j): string => preg_replace('/"name": "grafida-shell-linux-x86_64"/', '"name": "other"', $j, 1) ?? '',
            'another host'       => static fn (string $j): string => preg_replace('#"url": "https://github.com/grafida/shell/releases/download/0.4.2/grafida-shell-macos-arm64"#', '"url": "https://evil.example/grafida-shell-macos-arm64"', $j, 1) ?? '',
            'another release'    => static fn (string $j): string => preg_replace('#/download/0.4.2/grafida-shell-windows#', '/download/0.1.0/grafida-shell-windows', $j, 1) ?? '',
        ];

        // A release the constraint does not allow, or a tag that is not the version's, with URLs to match.
        $tampered['a version outside the constraint'] = static fn (string $j): string => self::githubLock('0.3.0', '0.3.0')->toJson();
        $tampered['a tag that is not the version']    = static fn (string $j): string => self::githubLock('0.4.2', '0.4.1')->toJson();
        self::githubLock('0.4.1', 'v0.4.1')->verify($shell, 'x.lock');

        foreach ($tampered as $what => $edit) {
            $json = $edit(self::githubLock()->toJson());
            self::assertNotSame(self::githubLock()->toJson(), $json, $what);

            try {
                ShellLock::fromJson($json, 'x.lock')->verify($shell, 'x.lock');
                self::fail("accepted a lock with {$what}");
            } catch (PackagerException $e) {
                self::assertStringContainsString('x.lock', $e->getMessage(), $what);
            }
        }
    }
}
