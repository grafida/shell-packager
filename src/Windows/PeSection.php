<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Windows;

/** One entry of a PE section table. */
final readonly class PeSection
{
    public function __construct(
        public string $name,
        public int $virtualSize,
        public int $virtualAddress,
        public int $rawSize,
        public int $rawPointer,
        public int $characteristics,
    ) {
    }
}
