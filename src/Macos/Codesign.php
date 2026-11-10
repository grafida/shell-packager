<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Macos;

use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Process;

/**
 * Code signing (grafida/grafida#88). The identity comes from the environment, never from an app's
 * repository: `MACOS_SIGN_IDENTITY` names a "Developer ID Application: …" identity in the keychain.
 * Without it every binary is signed **ad hoc**.
 *
 * Either way it is signed with the **hardened runtime** (`--options runtime`), so an ad-hoc build
 * runs under the same restrictions as the notarised one: an entitlement a binary needs shows up
 * locally, before notarisation. A Developer ID signature also carries a secure timestamp.
 */
final readonly class Codesign
{
    public const IDENTITY_ENV = 'MACOS_SIGN_IDENTITY';

    public function __construct(public ?string $identity)
    {
    }

    public static function fromEnvironment(): self
    {
        $identity = getenv(self::IDENTITY_ENV);

        return new self(\is_string($identity) && trim($identity) !== '' ? trim($identity) : null);
    }

    public function adHoc(): bool
    {
        return $this->identity === null;
    }

    public function describe(): string
    {
        return $this->identity === null ? 'ad hoc' : $this->identity;
    }

    /** Signs a binary or a bundle; $entitlements is a .plist, or null for none. */
    public function sign(string $path, ?string $entitlements): void
    {
        $command = ['codesign', '--force', '--options', 'runtime', '--sign', $this->identity ?? '-'];

        if ($this->identity !== null) {
            $command[] = '--timestamp';
        }

        if ($entitlements !== null) {
            self::lint($entitlements);
            array_push($command, '--entitlements', $entitlements);
        }

        $command[] = $path;
        Process::mustRun($command);
    }

    /** Signs a disk image (no hardened runtime: it is not code). Developer ID only. */
    public function signImage(string $path): void
    {
        if ($this->identity === null) {
            return;
        }

        Process::mustRun(['codesign', '--force', '--timestamp', '--sign', $this->identity, $path]);
    }

    public static function verify(string $path): void
    {
        Process::mustRun(['codesign', '--verify', '--strict', '--verbose=2', $path]);
    }

    /** True when the signature asks for the hardened runtime. */
    public static function hasHardenedRuntime(string $path): bool
    {
        [, $out, $err] = Process::run(['codesign', '-d', '--verbose=2', $path]);

        return preg_match('/^CodeDirectory .*flags=0x[0-9a-f]+\([^)]*\bruntime\b/m', $out . $err) === 1;
    }

    /**
     * The entitlements a signed binary carries, as data (an empty array for none).
     *
     * @return array<mixed>
     */
    public static function entitlements(string $path): array
    {
        [$code, $xml] = Process::run(['codesign', '-d', '--entitlements', '-', '--xml', $path]);

        if ($code !== 0) {
            throw new PackagerException("Cannot read the entitlements of {$path}.");
        }

        return trim($xml) === '' ? [] : self::plistData($xml, $path);
    }

    /**
     * An entitlements file, as data.
     *
     * @return array<mixed>
     */
    public static function entitlementsFile(?string $file): array
    {
        return $file === null ? [] : self::plistData(\Grafida\ShellPackager\Filesystem::read($file), $file);
    }

    private static function lint(string $plist): void
    {
        [$code, $out, $err] = Process::run(['plutil', '-lint', $plist]);

        if ($code !== 0) {
            throw new PackagerException("{$plist} is not a valid property list: " . trim($out . $err));
        }
    }

    /** @return array<mixed> */
    private static function plistData(string $xml, string $what): array
    {
        $json = Process::mustRun(['plutil', '-convert', 'json', '-o', '-', '-'], $xml);
        $data = json_decode($json, true);

        if (!\is_array($data)) {
            throw new PackagerException("{$what}: not a property list holding a dictionary.");
        }

        return $data;
    }
}
