<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Windows;

use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Process;

/**
 * Authenticode signing with Jsign, which runs on any host (the Mac signs Windows builds;
 * grafida/grafida#91). Everything comes from the environment, never from an app's repository:
 *
 * - **Azure Trusted Signing** (Artifact Signing): `WINDOWS_SIGN_ENDPOINT` (the account's endpoint,
 *   e.g. `weu.codesigning.azure.net`), `WINDOWS_SIGN_PROFILE` (`<account>/<certificate profile>`) and
 *   `WINDOWS_SIGN_TOKEN` (an access token for `https://codesigning.azure.net`, which the caller
 *   obtains, e.g. `az account get-access-token`). `WINDOWS_SIGN_TSAURL` overrides the timestamp
 *   server (default Microsoft's).
 * - **A local PKCS#12 keystore**, for tests: `WINDOWS_SIGN_PKCS12` and `WINDOWS_SIGN_PKCS12_PASSWORD`
 *   (a throwaway self-signed certificate on CI).
 * - Neither: nothing is signed, and every step says so.
 *
 * The token and the password reach Jsign as `env:` references, never on its command line.
 *
 * A signature is checked with `osslsigncode verify`: the digest it carries must equal the one
 * computed from the file, and the signer is reported. The certificate chain is checked only when
 * `WINDOWS_SIGN_CAFILE` names the roots to trust (osslsigncode has no Windows trust store; on CI it
 * is the throwaway certificate). Windows itself checks the chain in the guests
 * (`Get-AuthenticodeSignature`).
 */
final readonly class Authenticode
{
    public const ENDPOINT_ENV = 'WINDOWS_SIGN_ENDPOINT';
    public const PROFILE_ENV = 'WINDOWS_SIGN_PROFILE';
    public const TOKEN_ENV = 'WINDOWS_SIGN_TOKEN';
    public const TSAURL_ENV = 'WINDOWS_SIGN_TSAURL';
    public const PKCS12_ENV = 'WINDOWS_SIGN_PKCS12';
    public const PKCS12_PASSWORD_ENV = 'WINDOWS_SIGN_PKCS12_PASSWORD';
    public const CAFILE_ENV = 'WINDOWS_SIGN_CAFILE';
    /** Where `windows-sign` records what it signed (set by the packager around makensis). */
    public const LOG_ENV = 'SHELL_PACKAGER_SIGN_LOG';

    private const DEFAULT_TSAURL = 'http://timestamp.acs.microsoft.com';

    /** @param 'none'|'trusted'|'pkcs12' $mode */
    public function __construct(
        public string $mode,
        public ?string $endpoint = null,
        public ?string $profile = null,
        public ?string $keystore = null,
        public ?string $tsaUrl = null,
        public ?string $caFile = null,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $env      = static fn (string $name): ?string => \is_string($v = getenv($name)) && trim($v) !== '' ? trim($v) : null;
        $endpoint = $env(self::ENDPOINT_ENV);
        $profile  = $env(self::PROFILE_ENV);
        $keystore = $env(self::PKCS12_ENV);
        $tsa      = $env(self::TSAURL_ENV);
        $ca       = $env(self::CAFILE_ENV);

        if ($endpoint !== null || $profile !== null) {
            if ($endpoint === null || $profile === null || $env(self::TOKEN_ENV) === null) {
                throw new PackagerException(
                    'Trusted Signing needs all of ' . self::ENDPOINT_ENV . ', ' . self::PROFILE_ENV . ' and ' . self::TOKEN_ENV . '.'
                );
            }

            if ($keystore !== null) {
                throw new PackagerException('Set either Trusted Signing (' . self::ENDPOINT_ENV . ') or a keystore (' . self::PKCS12_ENV . '), not both.');
            }

            return new self('trusted', endpoint: $endpoint, profile: $profile, tsaUrl: $tsa ?? self::DEFAULT_TSAURL, caFile: $ca);
        }

        if ($keystore !== null) {
            if (!is_file($keystore)) {
                throw new PackagerException(self::PKCS12_ENV . "={$keystore} is not a file.");
            }

            if ($env(self::PKCS12_PASSWORD_ENV) === null) {
                throw new PackagerException(self::PKCS12_ENV . ' needs ' . self::PKCS12_PASSWORD_ENV . '.');
            }

            return new self('pkcs12', keystore: $keystore, tsaUrl: $tsa, caFile: $ca);
        }

        return new self('none');
    }

    public function enabled(): bool
    {
        return $this->mode !== 'none';
    }

    public function describe(): string
    {
        return match ($this->mode) {
            'trusted' => "Trusted Signing ({$this->endpoint}, {$this->profile})",
            'pkcs12'  => 'the keystore ' . basename((string) $this->keystore),
            default   => 'unsigned (no ' . self::ENDPOINT_ENV . ' or ' . self::PKCS12_ENV . ')',
        };
    }

    /**
     * Signs $path in place and checks the result. $hasOverlay is for an installer (NSIS keeps its
     * data past the image); anything else with an overlay is refused, since a phpmicro SFX with
     * its PHAR appended would be signed past the PHAR and corrupted.
     */
    public function sign(string $path, bool $hasOverlay = false): string
    {
        if (!$this->enabled()) {
            throw new PackagerException("Cannot sign {$path}: no signing is configured.");
        }

        $pe = PeFile::read($path);

        if (!$hasOverlay && $pe->overlayBytes() > 0) {
            throw new PackagerException(
                "Refusing to sign {$path}: it carries {$pe->overlayBytes()} bytes past its image (an SFX with its PHAR still "
                . 'appended?). The signature would go after the PHAR and corrupt it.'
            );
        }

        $command = ['jsign'];

        if ($this->mode === 'trusted') {
            array_push(
                $command,
                '--storetype', 'TRUSTEDSIGNING',
                '--keystore', (string) $this->endpoint,
                '--storepass', 'env:' . self::TOKEN_ENV,
                '--alias', (string) $this->profile,
            );
        } else {
            array_push($command, '--storetype', 'PKCS12', '--keystore', (string) $this->keystore, '--storepass', 'env:' . self::PKCS12_PASSWORD_ENV);
        }

        if ($this->tsaUrl !== null) {
            array_push($command, '--tsaurl', $this->tsaUrl, '--tsmode', 'RFC3161');
        }

        array_push($command, '--replace', $path);

        if (!Process::exists('jsign')) {
            throw new PackagerException('Signing needs Jsign on PATH (brew install jsign, or https://ebourg.github.io/jsign/).');
        }

        Process::mustRun($command);
        $subject = self::verify($path, $this->caFile);
        $record  = getenv(self::LOG_ENV);

        if (\is_string($record) && $record !== '') {
            file_put_contents($record, "{$path}\t{$subject}\n", \FILE_APPEND);
        }

        return $subject;
    }

    /** Checks the signature on $path; returns the signer's subject. */
    public static function verify(string $path, ?string $caFile = null): string
    {
        if (!Process::exists('osslsigncode')) {
            throw new PackagerException('Checking a signature needs osslsigncode on PATH (brew install osslsigncode).');
        }

        $command = ['osslsigncode', 'verify'];

        if ($caFile !== null) {
            array_push($command, '-CAfile', $caFile);
        }

        array_push($command, '-in', $path);
        [$code, $out, $err] = Process::run($command);
        $text = $out . $err;

        if (preg_match('/^Current message digest\s*:\s*([0-9A-F]+)/mi', $text, $current) !== 1
            || preg_match('/^Calculated message digest\s*:\s*([0-9A-F]+)/mi', $text, $calculated) !== 1) {
            if ($caFile === null && str_contains($text, '-CAfile')) {
                // Older osslsigncode (Ubuntu 22.04's 2.2) reads nothing without roots to check against.
                throw new PackagerException('This osslsigncode checks a signature only against given roots: set ' . self::CAFILE_ENV . ".\n" . trim($text));
            }

            throw new PackagerException("{$path} carries no readable Authenticode signature:\n" . trim($text));
        }

        if (strcasecmp($current[1], $calculated[1]) !== 0) {
            throw new PackagerException("{$path}: the signed digest does not match the file ({$current[1]} against {$calculated[1]}).");
        }

        if ($caFile !== null && ($code !== 0 || !str_contains($text, 'Succeeded'))) {
            throw new PackagerException("{$path}: the signature does not chain to {$caFile}:\n" . trim($text));
        }

        if (preg_match('/Signer #0:\s*\n\s*Subject:\s*(.+)/', $text, $subject) !== 1) {
            return 'an unnamed signer';
        }

        // Older osslsigncode (Ubuntu 22.04's) prints OpenSSL's one-line form, /CN=a/O=b.
        $name = trim($subject[1]);

        return str_starts_with($name, '/') ? implode(', ', explode('/', ltrim($name, '/'))) : $name;
    }
}
