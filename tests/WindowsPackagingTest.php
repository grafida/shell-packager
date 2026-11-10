<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\Config;
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Process;
use Grafida\ShellPackager\Windows\Authenticode;
use Grafida\ShellPackager\Windows\PeFile;
use Grafida\ShellPackager\Windows\Resources;
use Grafida\ShellPackager\Windows\ResourceWriter;
use Grafida\ShellPackager\Windows\VersionInfo;
use Grafida\ShellPackager\Windows\WindowsInstaller;
use Grafida\ShellPackager\Windows\WindowsTree;
use PHPUnit\Framework\TestCase;

/** The `windows` section, the tree, signing and the installer (grafida/grafida#91). */
final class WindowsPackagingTest extends TestCase
{
    private const ENV = [
        Authenticode::ENDPOINT_ENV, Authenticode::PROFILE_ENV, Authenticode::TOKEN_ENV, Authenticode::TSAURL_ENV,
        Authenticode::PKCS12_ENV, Authenticode::PKCS12_PASSWORD_ENV, Authenticode::CAFILE_ENV,
    ];

    private string $dir;

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->dir = Filesystem::tempDir('windows-test');

        foreach (self::ENV as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        Filesystem::remove($this->dir);
    }

    public function testTheFixturesSectionIsReadWithItsDefaults(): void
    {
        $config  = Config::load(__DIR__ . '/fixtures/app/shell-package.json');
        $windows = $config->windows();

        self::assertSame('Packager Test', $windows->name);
        self::assertStringEndsWith('icons/app.ico', (string) $windows->icon);
        self::assertSame('Fixture & Co', $windows->companyName);
        self::assertSame('Packager Test', $windows->productName);
        self::assertSame('Packager Test', $windows->shellDescription);
        self::assertSame('Packager Test worker', $windows->workerDescription);
        self::assertSame(['PUBLISHER_URL' => 'https://example.invalid', 'SHORTCUTS' => 'no'], $windows->defines);
        self::assertSame('Packager Test.exe', WindowsTree::shellExe($config));
        self::assertSame('test-worker.exe', WindowsTree::workerExe($config));
        self::assertStringEndsWith('/dist/Packager Test-1.0.0-windows-amd64-Setup.exe', WindowsTree::installerPath($config, '1.0.0'));
        self::assertArrayHasKey('windows', $config->runtimes);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badSections(): iterable
    {
        yield 'a name with .exe' => [['name' => 'App.exe'], 'without .exe'];
        yield 'a define the packager passes' => [['installer' => ['defines' => ['APPEXE' => 'x']]], 'may not set APPEXE'];
        yield 'an unknown version string' => [['version' => ['comments' => 'x']], 'not a version string'];
        yield 'a file outside the tree' => [['files' => ['../evil' => 'LICENSE.txt']], 'maps a name in the tree'];
    }

    /** @param array<string, mixed> $windows */
    #[\PHPUnit\Framework\Attributes\DataProvider('badSections')]
    public function testABadSectionIsRefused(array $windows, string $message): void
    {
        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage($message);
        Config::load($this->appWith($windows));
    }

    public function testTheTreeIsAssembledWithResources(): void
    {
        $config = Config::load(__DIR__ . '/fixtures/app/shell-package.json');
        $tree   = $this->dir . '/tree';
        WindowsTree::assemble($config, $this->runtimes(), $this->payload(), $tree, '1.2.3-beta4');

        $files = array_values(array_diff((array) scandir($tree), ['.', '..']));
        sort($files);
        self::assertSame(['LICENSE.txt', 'Packager Test.exe', 'Packager Test.ico', 'shell.json', 'test-worker.exe', 'test-worker.phar'], $files);
        self::assertSame('payload', file_get_contents("{$tree}/test-worker.phar"));

        foreach (['Packager Test.exe' => 'Packager Test', 'test-worker.exe' => 'Packager Test worker'] as $exe => $description) {
            $resources = ResourceWriter::existing(PeFile::read("{$tree}/{$exe}"));
            $version   = VersionInfo::decode($resources->ofType(Resources::RT_VERSION)[0]->data);
            self::assertSame($description, $version['strings']['FileDescription']);
            self::assertSame($exe, $version['strings']['OriginalFilename']);
            self::assertSame('1.2.3-beta4', $version['strings']['ProductVersion']);
            self::assertSame([1, 2, 3, 13], $version['fileVersion']);
            self::assertCount(1, $resources->ofType(Resources::RT_GROUP_ICON));
            self::assertCount(1, $resources->ofType(Resources::RT_MANIFEST));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function badBinaries(): iterable
    {
        yield 'a console program' => [PeBuilder::bytes(subsystem: PeFile::SUBSYSTEM_CONSOLE), 'console program'];
        yield 'an SFX with its payload' => [PeBuilder::bytes(overlay: str_repeat('P', 600)), 'still appended'];
        yield 'a signed stub' => [PeBuilder::bytes(certificate: str_repeat('C', 600)), 'signed already'];
        yield 'an x86 build' => [PeBuilder::bytes(machine: 0x14c, pe32: true), 'x86_64 only'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badBinaries')]
    public function testABadBinaryIsRefused(string $sfx, string $message): void
    {
        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage($message);
        $config   = Config::load(__DIR__ . '/fixtures/app/shell-package.json');
        $runtimes = $this->runtimes();
        Filesystem::write($runtimes['sfx'], $sfx);
        WindowsTree::assemble($config, $runtimes, $this->payload(), $this->dir . '/tree', '1.0.0');
    }

    public function testSigningComesFromTheEnvironment(): void
    {
        self::assertFalse(Authenticode::fromEnvironment()->enabled());

        putenv(Authenticode::ENDPOINT_ENV . '=weu.codesigning.azure.net');
        putenv(Authenticode::PROFILE_ENV . '=account/profile');
        putenv(Authenticode::TOKEN_ENV . '=secret');
        $trusted = Authenticode::fromEnvironment();
        self::assertSame('trusted', $trusted->mode);
        self::assertSame('http://timestamp.acs.microsoft.com', $trusted->tsaUrl);
        self::assertStringNotContainsString('secret', $trusted->describe());

        putenv(Authenticode::TOKEN_ENV);
        $this->expectExceptionMessage('needs all of');
        Authenticode::fromEnvironment();
    }

    public function testAStubWithItsPayloadIsNeverSigned(): void
    {
        $keystore = $this->keystore();
        $path     = $this->dir . '/combined.exe';
        Filesystem::write($path, PeBuilder::bytes(overlay: str_repeat('P', 600)));

        $this->expectExceptionMessage('Refusing to sign');
        $keystore->sign($path);
    }

    public function testSigningAndCheckingASignature(): void
    {
        $signer = $this->keystore();
        $config = Config::load(__DIR__ . '/fixtures/app/shell-package.json');
        $tree   = $this->dir . '/tree';
        WindowsTree::assemble($config, $this->runtimes(), $this->payload(), $tree, '1.0.0');

        self::assertSame('CN=shell-packager test signer', WindowsTree::sign($config, $tree, $signer));

        foreach (['Packager Test.exe', 'test-worker.exe'] as $exe) {
            $pe = PeFile::read("{$tree}/{$exe}");
            self::assertTrue($pe->isSigned(), $exe);
            self::assertSame(0, $pe->overlayBytes(), "{$exe}: the signature is not an overlay");
            self::assertSame('CN=shell-packager test signer', Authenticode::verify("{$tree}/{$exe}", $signer->caFile));
        }

        // A changed byte is a digest that no longer matches.
        $path = "{$tree}/test-worker.exe";
        $data = Filesystem::read($path);
        Filesystem::write($path, substr_replace($data, "\x90", 0x500, 1));
        $this->expectExceptionMessage('does not match');
        Authenticode::verify($path, $signer->caFile);
    }

    public function testTheInstallerIsBuiltSignedWithItsUninstaller(): void
    {
        if (!Process::exists('makensis')) {
            self::markTestSkipped('makensis is not installed.');
        }

        $signer = $this->keystore();
        $config = Config::load(__DIR__ . '/fixtures/app/shell-package.json');
        $tree   = $this->dir . '/Packager Test';
        WindowsTree::assemble($config, $this->runtimes(), $this->payload(), $tree, '1.2.3-beta4');
        WindowsTree::sign($config, $tree, $signer);

        $output = $this->dir . '/Setup.exe';
        WindowsInstaller::build($config, $tree, $output, '1.2.3-beta4', WindowsInstaller::signCommand(), static fn (string $line) => null);
        $signer->sign($output, hasOverlay: true);

        $pe = PeFile::read($output);
        self::assertFalse($pe->is64, 'NSIS writes an x86 installer');
        self::assertGreaterThan(0, $pe->overlayBytes(), 'NSIS keeps its data past the image');
        self::assertTrue($pe->isSigned());
        $version = VersionInfo::decode(ResourceWriter::existing($pe)->ofType(Resources::RT_VERSION)[0]->data);
        self::assertSame('Packager Test installer', $version['strings']['FileDescription']);
        self::assertSame([1, 2, 3, 13], $version['fileVersion']);
    }

    public function testAScriptThatWouldShipAnUnsignedUninstallerIsRefused(): void
    {
        if (!Process::exists('makensis')) {
            self::markTestSkipped('makensis is not installed.');
        }

        $config = Config::load($this->appWith(['installer' => ['script' => 'plain.nsi']], ['plain.nsi' => "OutFile \"\${OUTFILE}\"\nSection\nSectionEnd\n"]));
        $this->expectExceptionMessage('does not use SHELL_PACKAGER_SIGN');
        WindowsInstaller::build($config, $this->dir, $this->dir . '/x.exe', '1.0', '"php" x windows-sign --file="%1"', static fn (string $line) => null);
    }

    /** @return array{shell: string, sfx: string} */
    private function runtimes(): array
    {
        Filesystem::write($this->dir . '/shell.exe', PeBuilder::bytes());
        Filesystem::write($this->dir . '/sfx.exe', PeBuilder::bytes());

        return ['shell' => $this->dir . '/shell.exe', 'sfx' => $this->dir . '/sfx.exe'];
    }

    private function payload(): string
    {
        Filesystem::write($this->dir . '/payload.phar', 'payload');

        return $this->dir . '/payload.phar';
    }

    /** A throwaway self-signed code-signing certificate, as CI signs with. */
    private function keystore(): Authenticode
    {
        foreach (['jsign', 'osslsigncode', 'openssl'] as $tool) {
            if (!Process::exists($tool)) {
                self::markTestSkipped("{$tool} is not installed.");
            }
        }

        $key = $this->dir . '/test.key';
        $crt = $this->dir . '/test.crt';
        $p12 = $this->dir . '/test.p12';
        Process::mustRun(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $key, '-out', $crt, '-days', '2',
            '-subj', '/CN=shell-packager test signer', '-addext', 'extendedKeyUsage=codeSigning']);
        Process::mustRun(['openssl', 'pkcs12', '-export', '-inkey', $key, '-in', $crt, '-out', $p12, '-passout', 'pass:test', '-name', 'test']);
        putenv(Authenticode::PKCS12_ENV . "={$p12}");
        putenv(Authenticode::PKCS12_PASSWORD_ENV . '=test');
        putenv(Authenticode::CAFILE_ENV . "={$crt}");

        return Authenticode::fromEnvironment();
    }

    /**
     * A copy of the fixture app's configuration with its `windows` section changed.
     *
     * @param array<string, mixed>  $windows
     * @param array<string, string> $files  extra files in the app
     */
    private function appWith(array $windows, array $files = []): string
    {
        $app = $this->dir . '/app';
        Filesystem::mkdir($app . '/icons');

        foreach (['shell.json', 'LICENSE.txt', 'icons/app.ico', 'installer.nsi'] as $f) {
            copy(__DIR__ . '/fixtures/app/' . $f, "{$app}/{$f}");
        }

        foreach ($files as $name => $content) {
            Filesystem::write("{$app}/{$name}", $content);
        }

        $data            = json_decode(Filesystem::read(__DIR__ . '/fixtures/app/shell-package.json'), true, 64, \JSON_THROW_ON_ERROR);
        \assert(\is_array($data) && \is_array($data['windows']));
        $data['windows'] = array_replace($data['windows'], $windows);
        unset($data['linux'], $data['macos']);
        Filesystem::write("{$app}/shell-package.json", json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));

        return "{$app}/shell-package.json";
    }
}
