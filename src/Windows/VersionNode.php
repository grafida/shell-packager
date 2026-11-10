<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Windows;

/** One node of a version resource, as read back: its key, its value's bytes and its children. */
final readonly class VersionNode
{
    /** @param list<VersionNode> $children */
    public function __construct(
        public string $key,
        public string $value,
        public array $children,
    ) {
    }
}
