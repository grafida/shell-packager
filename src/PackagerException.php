<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager;

/** Anything that stops a package being built; the message is meant for the person running it. */
final class PackagerException extends \RuntimeException
{
}
