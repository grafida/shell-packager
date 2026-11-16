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
use Grafida\ShellPackager\Process;

/**
 * The releases through the logged-in `gh` CLI: its own credentials, and its own pagination.
 * `--jq` prints one release per line, so only stdout is parsed; whatever `gh` says on stderr (an
 * update notice) never reaches the JSON.
 */
final class GhReleaseSource implements ReleaseSource
{
    private const string JQ = '.[] | {tag_name, draft, assets: [.assets[] | {name, browser_download_url, digest}]}';

    public function releases(string $repository): array
    {
        [$code, $out, $err] = Process::run(['gh', 'api', '--paginate', "repos/{$repository}/releases?per_page=100", '--jq', self::JQ]);

        if ($code !== 0) {
            throw new PackagerException("gh could not list the releases of {$repository} (it must exist and be public):\n" . trim($err));
        }

        return self::parse($out);
    }

    public function describe(): string
    {
        return 'gh';
    }

    /** @return list<Release> */
    public static function parse(string $ndjson): array
    {
        $releases = [];

        foreach (explode("\n", $ndjson) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $data = json_decode($line, true);

            if (!\is_array($data)) {
                throw new PackagerException('gh returned a release listing this packager cannot read: ' . substr($line, 0, 200));
            }

            $releases[] = Release::fromApi($data);
        }

        return $releases;
    }
}
