<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Shell;

use Grafida\ShellPackager\Process;

/** Picks how the releases are listed: a logged-in `gh` when there is one, otherwise curl. */
final class ReleaseSources
{
    /** @param ?\Closure(): bool $ghUsable whether `gh` is installed and logged in */
    public static function detect(?\Closure $ghUsable = null): ReleaseSource
    {
        $ghUsable ??= static fn (): bool => Process::exists('gh') && Process::run(['gh', 'auth', 'status'])[0] === 0;

        if ($ghUsable()) {
            return new GhReleaseSource();
        }

        foreach (['GH_TOKEN', 'GITHUB_TOKEN'] as $name) {
            $token = getenv($name);

            if (\is_string($token) && $token !== '') {
                return new HttpReleaseSource($token);
            }
        }

        return new HttpReleaseSource(null);
    }
}
