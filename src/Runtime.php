<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager;

/**
 * A prebuilt binary the package is made of (the shell, the launcher, the phpmicro SFX): where to
 * download it per architecture, and the SHA-256 it must have. The pin is the app's, committed in
 * its configuration; a hash published beside the download is never consulted, because that would
 * only detect corruption, not substitution.
 *
 * `{arch}` in the URL stands for the architecture (x86_64/aarch64). A URL without it names one file.
 * When the architectures come from different releases (macOS: the arm64 and x86_64 pins of one app
 * were verified on different shell tags and SFX releases; grafida/grafida#92), `url` is instead an
 * object mapping each architecture to its own URL, which may still use `{arch}`.
 */
final readonly class Runtime
{
    /**
     * @param string|array<string, string> $url    one URL (with or without `{arch}`), or arch name => URL
     * @param array<string, string>        $sha256 arch name => lower-case hex digest
     * @param bool                         $latest the app asked for the newest release instead of a pin
     */
    public function __construct(
        public string $name,
        public string|array $url,
        public array $sha256,
        public bool $latest = false,
    ) {
    }

    /**
     * The newest release of a runtime, per platform and architecture: `{"latest": true}` in the
     * configuration. GitHub's `releases/latest/download` redirect names the shell and launcher; the
     * SFX comes from phpmicro's rolling `sfx-latest` release.
     *
     * @return array<string, string> arch name => URL
     */
    public static function latestUrls(string $name, string $platform): array
    {
        $shell = 'https://github.com/grafida/shell/releases/latest/download/';
        $sfx   = 'https://github.com/grafida/phpmicro/releases/download/sfx-latest/';

        $urls = match ([$name, $platform]) {
            ['shell', 'linux']     => [Arch::X86_64->value => $shell . 'grafida-shell-linux-x86_64', Arch::Aarch64->value => $shell . 'grafida-shell-linux-aarch64'],
            ['shell', 'macos']     => [Arch::X86_64->value => $shell . 'grafida-shell-macos-x86_64', Arch::Aarch64->value => $shell . 'grafida-shell-macos-arm64'],
            ['shell', 'windows']   => [Arch::X86_64->value => $shell . 'grafida-shell-windows-x86_64.exe'],
            ['launcher', 'linux']  => [Arch::X86_64->value => $shell . 'grafida-launcher-linux-x86_64', Arch::Aarch64->value => $shell . 'grafida-launcher-linux-aarch64'],
            ['sfx', 'linux'],
            ['sfx', 'macos']       => [Arch::X86_64->value => $sfx . "{$platform}-x86_64.standard.sfx", Arch::Aarch64->value => $sfx . "{$platform}-aarch64.standard.sfx"],
            ['sfx', 'windows']     => [Arch::X86_64->value => $sfx . 'windows-x86_64.standard.sfx'],
            default                => null,
        };

        return $urls ?? throw new PackagerException("There is no 'latest' release of the {$platform} '{$name}' runtime: pin a url and sha256 instead.");
    }

    /** @param array<mixed> $data */
    public static function fromArray(string $name, array $data, string $platform = 'linux'): self
    {
        if (\array_key_exists('latest', $data)) {
            if ($data['latest'] !== true || array_diff(array_keys($data), ['latest']) !== []) {
                throw new PackagerException("runtimes.{$platform}.{$name} is either pinned (url and sha256) or {\"latest\": true}, never both.");
            }

            return new self($name, self::latestUrls($name, $platform), [], true);
        }

        $url = $data['url'] ?? null;

        if (\is_array($url)) {
            $urls = [];

            foreach ($url as $arch => $one) {
                $arch = Arch::parse((string) $arch)->value;

                if (!\is_string($one) || $one === '') {
                    throw new PackagerException("runtimes.{$name}.url.{$arch} must be a URL.");
                }

                $urls[$arch] = $one;
            }

            if ($urls === []) {
                throw new PackagerException("runtimes.{$name}.url must map at least one architecture to a URL.");
            }

            $url = $urls;
        } elseif (!\is_string($url) || $url === '') {
            throw new PackagerException("runtimes.{$name}.url must be a URL, or map each architecture to one.");
        }

        $pins = $data['sha256'] ?? null;

        if (!\is_array($pins)) {
            throw new PackagerException("runtimes.{$name}.sha256 must map each architecture to a SHA-256.");
        }

        $sha256 = [];

        foreach ($pins as $arch => $hash) {
            $arch = Arch::parse((string) $arch)->value;

            if (!\is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new PackagerException("runtimes.{$name}.sha256.{$arch} must be 64 lower-case hex digits.");
            }

            $sha256[$arch] = $hash;
        }

        return new self($name, $url, $sha256);
    }

    public function url(Arch $arch): string
    {
        $url = \is_array($this->url)
            ? ($this->url[$arch->value] ?? throw new PackagerException("No URL is given for {$this->name} on {$arch->value} (runtimes.{$this->name}.url.{$arch->value})."))
            : $this->url;

        return str_replace('{arch}', $arch->value, $url);
    }

    /** The SHA-256 this architecture must have. Not for a `latest` runtime, which has none. */
    public function pin(Arch $arch): string
    {
        return $this->sha256[$arch->value]
            ?? throw new PackagerException("No SHA-256 is pinned for {$this->name} on {$arch->value} (runtimes.{$this->name}.sha256.{$arch->value}).");
    }
}
