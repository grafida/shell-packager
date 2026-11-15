<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Linux;

use Grafida\ShellPackager\PackagerException;

/**
 * `linux.pkgbuild` (grafida/grafida#87), what an Arch `PKGBUILD` needs that the manifest does not say:
 *
 * ```jsonc
 * "pkgbuild": {
 *   "source": "https://example.com/dl/{name}-{version}-linux-{debarch}.tar.gz",  // where the tarball is published
 *   "description": "Edit Joomla articles",
 *   "url": "https://example.com",
 *   "license": ["GPL-3.0-or-later"],        // SPDX identifiers
 *   "maintainer": "Jane Doe <jane@example.com>",   // optional
 *   "depends": ["libsecret"],                // in addition to webkit2gtk-4.1 and gtk3
 *   "optdepends": ["wl-clipboard: paste on Wayland"],
 *   "pkgrel": 1,
 *   "output": "build/dist/PKGBUILD"
 * }
 * ```
 *
 * `{name}`, `{version}`, `{arch}` (x86_64/aarch64) and `{debarch}` (amd64/arm64) are expanded in
 * `source`. Nothing here is published anywhere.
 */
final readonly class PkgbuildConfig
{
    /** The system packages the shell needs (webkit2gtk-4.1, gtk3; namcap adds the icon theme and D-Bus), which the packager supplies so no app repeats them. */
    public const DEFAULT_DEPENDS = ['webkit2gtk-4.1', 'gtk3', 'hicolor-icon-theme', 'dbus'];

    /**
     * @param list<string> $license
     * @param list<string> $depends
     * @param list<string> $optdepends
     */
    public function __construct(
        public string $source,
        public string $description,
        public string $url,
        public array $license,
        public ?string $maintainer = null,
        public array $depends = [],
        public array $optdepends = [],
        public int $pkgrel = 1,
        public string $output = 'build/dist/PKGBUILD',
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $string = static function (string $key, bool $required = true) use ($data): ?string {
            $value = $data[$key] ?? null;

            if ($value === null && !$required) {
                return null;
            }

            if (!\is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1f]/', $value) === 1) {
                throw new PackagerException("linux.pkgbuild.{$key} must be a single-line string.");
            }

            return $value;
        };

        $list = static function (string $key, string $pattern) use ($data): array {
            $value = $data[$key] ?? [];

            if (!\is_array($value) || !array_is_list($value)) {
                throw new PackagerException("linux.pkgbuild.{$key} must be a list of strings.");
            }

            foreach ($value as $item) {
                if (!\is_string($item) || preg_match($pattern, $item) !== 1) {
                    throw new PackagerException("linux.pkgbuild.{$key}: unacceptable entry " . json_encode($item) . '.');
                }
            }

            /** @var list<string> $value */
            return $value;
        };

        $source = (string) $string('source');

        if (!str_starts_with($source, 'https://')) {
            throw new PackagerException('linux.pkgbuild.source must be an https:// URL.');
        }

        $license = $list('license', '/^[A-Za-z0-9][A-Za-z0-9.+ -]*$/');

        if ($license === []) {
            throw new PackagerException('linux.pkgbuild.license is required: a list of SPDX identifiers.');
        }

        $pkgrel = $data['pkgrel'] ?? 1;

        if (!\is_int($pkgrel) || $pkgrel < 1) {
            throw new PackagerException('linux.pkgbuild.pkgrel must be a positive integer.');
        }

        return new self(
            source: $source,
            description: (string) $string('description'),
            url: (string) $string('url'),
            license: $license,
            maintainer: $string('maintainer', false),
            depends: $list('depends', '/^[a-z0-9@._+-]+([<>]?=[A-Za-z0-9._:+-]+)?$/'),
            optdepends: $list('optdepends', '/^[a-z0-9@._+-]+(: [^\x00-\x1f]+)?$/'),
            pkgrel: $pkgrel,
            output: \is_string($data['output'] ?? null) && $data['output'] !== '' ? $data['output'] : 'build/dist/PKGBUILD',
        );
    }
}
