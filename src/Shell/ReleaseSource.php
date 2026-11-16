<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Shell;

/** Lists the releases of a public GitHub repository. */
interface ReleaseSource
{
    /** @return list<Release> every release, drafts included (the resolver skips them) */
    public function releases(string $repository): array;

    /** How the releases are listed, for messages. */
    public function describe(): string;
}
