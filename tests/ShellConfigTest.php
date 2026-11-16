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
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Platform;
use Grafida\ShellPackager\Shell\ShellConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShellConfigTest extends TestCase
{
    private static function root(): string
    {
        return (string) realpath(Fixture::APP);
    }

    public function testDefaultsNameWhatGrafidaShellPublishes(): void
    {
        $shell = ShellConfig::fromArray(['version' => '^0.4'], self::root());

        self::assertSame('grafida/shell', $shell->repository);
        self::assertSame('^0.4', $shell->constraint);
        self::assertSame('grafida-shell-macos-arm64', $shell->asset(Platform::Macos, 'shell', Arch::Aarch64));
        self::assertSame('grafida-shell-macos-x86_64', $shell->asset(Platform::Macos, 'shell', Arch::X86_64));
        self::assertSame('grafida-launcher-linux-x86_64', $shell->asset(Platform::Linux, 'launcher', Arch::X86_64));
        self::assertSame('grafida-shell-linux-aarch64', $shell->asset(Platform::Linux, 'shell', Arch::Aarch64));
        self::assertSame('grafida-shell-windows-x86_64.exe', $shell->asset(Platform::Windows, 'shell', Arch::X86_64));
        self::assertSame(
            [
                'linux'   => ['launcher' => ['aarch64' => 'grafida-launcher-linux-aarch64', 'x86_64' => 'grafida-launcher-linux-x86_64'],
                              'shell'    => ['aarch64' => 'grafida-shell-linux-aarch64', 'x86_64' => 'grafida-shell-linux-x86_64']],
                'macos'   => ['shell' => ['aarch64' => 'grafida-shell-macos-arm64', 'x86_64' => 'grafida-shell-macos-x86_64']],
                'windows' => ['shell' => ['x86_64' => 'grafida-shell-windows-x86_64.exe']],
            ],
            $shell->assets
        );
    }

    public function testAnOverrideReplacesOnlyItsComponent(): void
    {
        $shell = ShellConfig::fromArray(['version' => '^0.4', 'assets' => ['linux' => ['shell' => 'foo-{arch}']]], self::root());

        self::assertSame('foo-aarch64', $shell->asset(Platform::Linux, 'shell', Arch::Aarch64));
        self::assertSame('foo-x86_64', $shell->asset(Platform::Linux, 'shell', Arch::X86_64));
        self::assertSame('grafida-launcher-linux-aarch64', $shell->asset(Platform::Linux, 'launcher', Arch::Aarch64));
        self::assertSame('grafida-shell-macos-arm64', $shell->asset(Platform::Macos, 'shell', Arch::Aarch64));

        $mapped = ShellConfig::fromArray(['version' => '^0.4', 'assets' => ['macos' => ['shell' => ['arm64' => 'm-a', 'amd64' => 'm-x']]]], self::root());
        self::assertSame('m-a', $mapped->asset(Platform::Macos, 'shell', Arch::Aarch64));
        self::assertSame('m-x', $mapped->asset(Platform::Macos, 'shell', Arch::X86_64));
    }

    /** @return array<string, array{array<mixed>, string}> */
    public static function unknownSlots(): array
    {
        return [
            'platform'             => [['freebsd' => ['shell' => 'x']], 'shell.assets.freebsd'],
            'component'            => [['macos' => ['launcher' => 'x']], 'shell.assets.macos.launcher'],
            'architecture'         => [['windows' => ['shell' => ['x86_64' => 'a', 'aarch64' => 'b']]], 'shell.assets.windows.shell.aarch64'],
            'a missing arch'       => [['macos' => ['shell' => ['aarch64' => 'a']]], 'shell.assets.macos.shell.x86_64'],
            'not an object'        => [['linux' => 'x'], 'shell.assets.linux'],
        ];
    }

    /** @param array<mixed> $assets */
    #[DataProvider('unknownSlots')]
    public function testUnknownSlotsAreRefused(array $assets, string $key): void
    {
        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage($key);
        ShellConfig::fromArray(['version' => '^0.4', 'assets' => $assets], self::root());
    }

    public function testLocalIsRelativeToTheConfigAndMayBeSparse(): void
    {
        $shell = ShellConfig::fromArray(['version' => '^0.4', 'local' => ['macos' => ['shell' => ['aarch64' => 'bin/worker.php']]]], self::root());

        self::assertSame(self::root() . '/bin/worker.php', $shell->local(Platform::Macos, 'shell', Arch::Aarch64));
        self::assertNull($shell->local(Platform::Macos, 'shell', Arch::X86_64));
        self::assertNull($shell->local(Platform::Linux, 'shell', Arch::X86_64));

        $dir = Filesystem::tempDir('shell-config-test');

        try {
            Filesystem::write($dir . '/s-x86_64', 'x');
            Filesystem::write($dir . '/s-aarch64', 'a');
            $template = ShellConfig::fromArray(['version' => '^0.4', 'local' => ['linux' => ['shell' => 's-{arch}']]], $dir);
            self::assertSame($dir . '/s-x86_64', $template->local(Platform::Linux, 'shell', Arch::X86_64));
            self::assertSame($dir . '/s-aarch64', $template->local(Platform::Linux, 'shell', Arch::Aarch64));
        } finally {
            Filesystem::remove($dir);
        }

        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('shell.local.macos.shell.aarch64');
        ShellConfig::fromArray(['version' => '^0.4', 'local' => ['macos' => ['shell' => ['aarch64' => 'nope/missing']]]], self::root());
    }

    public function testTheContentHashIgnoresLocal(): void
    {
        $base  = ['version' => '^0.4', 'assets' => ['linux' => ['shell' => 'foo-{arch}']]];
        $hash  = ShellConfig::fromArray($base, self::root())->contentHash();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertSame($hash, ShellConfig::fromArray($base + ['local' => ['macos' => ['shell' => ['aarch64' => 'bin/worker.php']]]], self::root())->contentHash());
        self::assertNotSame($hash, ShellConfig::fromArray(['version' => '^0.5'] + $base, self::root())->contentHash());
        self::assertNotSame($hash, ShellConfig::fromArray($base + ['repository' => 'foobar/shell'], self::root())->contentHash());
        self::assertNotSame($hash, ShellConfig::fromArray(['assets' => ['linux' => ['shell' => 'bar-{arch}']]] + $base, self::root())->contentHash());
        // Spelling out a default changes nothing: the hash is of the effective names.
        self::assertSame(
            ShellConfig::fromArray(['version' => '^0.4'], self::root())->contentHash(),
            ShellConfig::fromArray(['version' => '^0.4', 'assets' => ['windows' => ['shell' => 'grafida-shell-windows-{arch}.exe']]], self::root())->contentHash()
        );
    }

    /** @return array<string, array{array<mixed>, string}> */
    public static function badValues(): array
    {
        return [
            'no version'        => [[], 'shell.version'],
            'bad constraint'    => [['version' => '^^1'], 'shell.version'],
            'bad repository'    => [['version' => '^0.4', 'repository' => 'nope'], 'shell.repository'],
            'a path asset'      => [['version' => '^0.4', 'assets' => ['linux' => ['shell' => 'dir/x-{arch}']]], 'shell.assets.linux.shell'],
            'an unknown key'    => [['version' => '^0.4', 'url' => 'https://x'], 'shell.url'],
        ];
    }

    /** @param array<mixed> $data */
    #[DataProvider('badValues')]
    public function testBadValuesAreRefused(array $data, string $key): void
    {
        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage($key);
        ShellConfig::fromArray($data, self::root());
    }

    public function testConfigReadsTheShellBlock(): void
    {
        $config = Fixture::config(shell: ['version' => '0.4.2']);

        self::assertNotNull($config->shell);
        self::assertSame('0.4.2', $config->shell()->constraint);
        self::assertStringEndsWith('.json', $config->path);
        self::assertSame(self::root(), \dirname($config->path));

        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('shell.version');
        Fixture::config(shell: false)->shell();
    }
}
