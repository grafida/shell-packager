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
 * `linux.appimage` (grafida/grafida#87, grafida/shell-packager#1):
 *
 * ```jsonc
 * "appimage": {
 *   "updateInformation": "gh-releases-zsync|myorg|myapp|latest|myapp-*-linux-{debarch}.AppImage.zsync",
 *   "output": "build/dist/{name}-{version}-linux-{arch}.AppImage",
 *   "squashfs": "auto",                              // "host", "docker" or "auto"
 *   "docker": {"image": "example/squashfs@sha256:…"} // optional: instead of the image the packager builds
 * }
 * ```
 *
 * `updateInformation` is only embedded in the AppImage (for AppImageUpdate and friends to read);
 * the packager neither installs an updater nor makes the `.zsync` file. `{name}`, `{arch}`
 * (x86_64/aarch64) and `{debarch}` (amd64/arm64) are expanded in it. `squashfs` and `docker` are
 * described in {@see Squashfs}.
 */
final readonly class AppImageConfig
{
    /** The `.upd_info` section of the type-2 runtime is 1024 bytes. */
    public const UPDATE_INFORMATION_MAX = 1024;

    public const SQUASHFS = ['auto', 'host', 'docker'];

    public function __construct(
        public ?string $updateInformation = null,
        public string $output = 'build/dist/{name}-{version}-linux-{arch}.AppImage',
        public string $squashfs = 'auto',
        public ?string $dockerImage = null,
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

        $squashfs = $data['squashfs'] ?? 'auto';

        if (!\in_array($squashfs, self::SQUASHFS, true)) {
            throw new PackagerException('linux.appimage.squashfs must be "auto", "host" or "docker".');
        }

        $docker = $data['docker'] ?? [];

        if (!\is_array($docker) || array_diff(array_keys($docker), ['image']) !== []) {
            throw new PackagerException('linux.appimage.docker is an object whose only member is "image".');
        }

        $image = $docker['image'] ?? null;

        // Pinned like every other input: a digest names one image forever, a tag does not.
        if ($image !== null && (!\is_string($image) || preg_match('/^[a-z0-9][a-z0-9._\/:-]*@sha256:[0-9a-f]{64}$/', $image) !== 1)) {
            throw new PackagerException('linux.appimage.docker.image must be pinned by digest: "name@sha256:<64 hex digits>" (a tag such as :latest can change).');
        }

        return new self($info, $output ?? 'build/dist/{name}-{version}-linux-{arch}.AppImage', $squashfs, $image);
    }
}
