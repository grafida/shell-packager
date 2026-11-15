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
 * `linux.appimage` (grafida/grafida#87):
 *
 * ```jsonc
 * "appimage": {
 *   "updateInformation": "gh-releases-zsync|myorg|myapp|latest|myapp-*-linux-{debarch}.AppImage.zsync",
 *   "output": "build/dist/{name}-{version}-linux-{arch}.AppImage"
 * }
 * ```
 *
 * `updateInformation` is only embedded in the AppImage (for AppImageUpdate and friends to read);
 * the packager neither installs an updater nor makes the `.zsync` file. `{name}`, `{arch}`
 * (x86_64/aarch64) and `{debarch}` (amd64/arm64) are expanded in it.
 */
final readonly class AppImageConfig
{
    /** The `.upd_info` section of the type-2 runtime is 1024 bytes. */
    public const UPDATE_INFORMATION_MAX = 1024;

    public function __construct(
        public ?string $updateInformation = null,
        public string $output = 'build/dist/{name}-{version}-linux-{arch}.AppImage',
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $info = $data['updateInformation'] ?? null;

        if ($info !== null) {
            // The AppImage spec's three transports; the value is a single line of printable ASCII.
            if (!\is_string($info) || preg_match('/^(zsync|gh-releases-zsync|bintray-zsync)\|[\x21-\x7e]+$/', $info) !== 1) {
                throw new PackagerException('linux.appimage.updateInformation must be "zsync|URL", "gh-releases-zsync|user|repo|tag|file" or "bintray-zsync|…", with no spaces.');
            }

            if (\strlen($info) >= self::UPDATE_INFORMATION_MAX) {
                throw new PackagerException('linux.appimage.updateInformation is longer than the runtime\'s 1024-byte section.');
            }
        }

        $output = $data['output'] ?? null;

        if ($output !== null && (!\is_string($output) || $output === '')) {
            throw new PackagerException('linux.appimage.output must be a path.');
        }

        return new self($info, $output ?? 'build/dist/{name}-{version}-linux-{arch}.AppImage');
    }
}
