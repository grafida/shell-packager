<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Windows;

/** One resource: its type and name (a numeric id, or a string), its language and its bytes. */
final readonly class ResourceEntry
{
    public function __construct(
        public int|string $type,
        public int|string $name,
        public int $lang,
        public string $data,
        public int $codePage = 0,
    ) {
    }
}
