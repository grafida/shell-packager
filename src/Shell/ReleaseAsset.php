<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Shell;

/** One file of a GitHub release: its name, its public download URL and GitHub's SHA-256 of it. */
final readonly class ReleaseAsset
{
    /** @param ?string $sha256 lower-case hex, from GitHub's `digest`; null when GitHub has none */
    public function __construct(
        public string $name,
        public string $url,
        public ?string $sha256,
    ) {
    }
}
