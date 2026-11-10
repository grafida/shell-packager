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
 * `{arch}` in the URL stands for the architecture (x86_64/aarch64). A URL without it names one file,
 * for a platform with a single architecture (macOS: arm64 only).
 */
final readonly class Runtime
{
    /** @param array<string, string> $sha256 arch name => lower-case hex digest */
    public function __construct(
        public string $name,
        public string $url,
        public array $sha256,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        $url = $data['url'] ?? null;

        if (!\is_string($url) || $url === '') {
            throw new PackagerException("runtimes.{$name}.url must be a URL.");
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
        return str_replace('{arch}', $arch->value, $this->url);
    }

    public function pin(Arch $arch): string
    {
        return $this->sha256[$arch->value]
            ?? throw new PackagerException("No SHA-256 is pinned for {$this->name} on {$arch->value} (runtimes.{$this->name}.sha256.{$arch->value}).");
    }
}
