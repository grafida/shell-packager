<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Shell;

use Grafida\ShellPackager\PackagerException;

/** A GitHub release, as much of it as resolving a version needs. */
final readonly class Release
{
    /** @param array<string, ReleaseAsset> $assets by name */
    public function __construct(
        public string $tag,
        public bool $draft,
        public array $assets,
    ) {
    }

    /**
     * One release from GitHub's REST payload (`tag_name`, `draft`, `assets[].name`,
     * `assets[].browser_download_url`, `assets[].digest`).
     *
     * @param array<mixed> $data
     */
    public static function fromApi(array $data): self
    {
        $tag   = $data['tag_name'] ?? null;
        $draft = $data['draft'] ?? false;
        $list  = $data['assets'] ?? [];

        if (!\is_string($tag) || $tag === '' || !\is_bool($draft) || !\is_array($list)) {
            throw new PackagerException('GitHub returned a release this packager cannot read (no tag_name, draft or assets).');
        }

        $assets = [];

        foreach ($list as $asset) {
            $name   = \is_array($asset) ? ($asset['name'] ?? null) : null;
            $url    = \is_array($asset) ? ($asset['browser_download_url'] ?? null) : null;
            $digest = \is_array($asset) ? ($asset['digest'] ?? null) : null;

            if (!\is_string($name) || $name === '' || !\is_string($url) || $url === '') {
                throw new PackagerException("GitHub returned an asset of release {$tag} without a name or download URL.");
            }

            $sha256 = null;

            if ($digest !== null) {
                if (!\is_string($digest) || preg_match('/^sha256:([0-9a-f]{64})$/', $digest, $m) !== 1) {
                    throw new PackagerException("Asset {$name} of release {$tag} has a digest that is not a SHA-256: " . json_encode($digest) . '.');
                }

                $sha256 = $m[1];
            }

            $assets[$name] = new ReleaseAsset($name, $url, $sha256);
        }

        return new self($tag, $draft, $assets);
    }
}
