<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Shell;

/** One shell file as the lock records it: the release asset's name, its download URL and SHA-256. */
final readonly class LockedFile
{
    public function __construct(
        public string $name,
        public string $url,
        public string $sha256,
    ) {
    }
}
