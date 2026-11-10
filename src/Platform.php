<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager;

/** The platforms a package is built for, under the names `runtimes` groups its pins by. */
enum Platform: string
{
    case Linux = 'linux';
    case Macos = 'macos';
    case Windows = 'windows';
}
