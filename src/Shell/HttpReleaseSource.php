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

/**
 * The releases straight from GitHub's REST API with curl, following the `Link` header's next
 * page. A token (GH_TOKEN, then GITHUB_TOKEN) is sent only here, for the rate limit; downloads
 * never carry one, since only public repositories are supported.
 */
final readonly class HttpReleaseSource implements ReleaseSource
{
    public function __construct(private ?string $token)
    {
    }

    public function releases(string $repository): array
    {
        $url      = "https://api.github.com/repos/{$repository}/releases?per_page=100";
        $releases = [];

        while ($url !== null) {
            [$status, $headers, $body] = $this->get($url);

            if ($status !== 200) {
                throw self::failure($repository, $status, $headers, $body);
            }

            array_push($releases, ...self::parse($body));
            $url = self::nextPage($headers);
        }

        return $releases;
    }

    public function describe(): string
    {
        return $this->token === null || $this->token === '' ? 'the GitHub API (unauthenticated)' : 'the GitHub API (token)';
    }

    /** @return list<Release> */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);

        if (!\is_array($data) || !array_is_list($data)) {
            throw new PackagerException('GitHub returned a release listing this packager cannot read: ' . substr($json, 0, 200));
        }

        $releases = [];

        foreach ($data as $release) {
            if (!\is_array($release)) {
                throw new PackagerException('GitHub returned a release listing this packager cannot read.');
            }

            $releases[] = Release::fromApi($release);
        }

        return $releases;
    }

    /** @param list<string> $headerLines */
    public static function nextPage(array $headerLines): ?string
    {
        foreach ($headerLines as $line) {
            if (stripos($line, 'link:') !== 0) {
                continue;
            }

            foreach (explode(',', substr($line, 5)) as $part) {
                if (preg_match('/<([^>]+)>\s*;\s*rel="next"/', $part, $m) === 1) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    /** @param list<string> $headerLines */
    public static function failure(string $repository, int $status, array $headerLines, string $body): PackagerException
    {
        $limited = $status === 429;

        foreach ($headerLines as $line) {
            if (preg_match('/^x-ratelimit-remaining:\s*0\s*$/i', $line) === 1) {
                $limited = true;
            }
        }

        if ($limited) {
            return new PackagerException(
                "GitHub's API rate limit stopped the release listing of {$repository} (HTTP {$status}). "
                . 'Log in with `gh auth login`, or set GH_TOKEN (or GITHUB_TOKEN) to a token, and try again.'
            );
        }

        if ($status === 404) {
            return new PackagerException("GitHub has no repository {$repository} (HTTP 404): shell.repository must exist and be public.");
        }

        return new PackagerException("GitHub refused the release listing of {$repository} (HTTP {$status}): " . substr(trim($body), 0, 300));
    }

    /** @return array{int, list<string>, string} status, header lines, body */
    private function get(string $url): array
    {
        $headers = [];
        $request = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];

        if ($this->token !== null && $this->token !== '') {
            $request[] = 'Authorization: Bearer ' . $this->token;
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS      => 5,
            \CURLOPT_CONNECTTIMEOUT => 30,
            \CURLOPT_TIMEOUT        => 120,
            \CURLOPT_USERAGENT      => 'grafida-shell-packager',
            \CURLOPT_HTTPHEADER     => $request,
            \CURLOPT_PROTOCOLS      => \CURLPROTO_HTTPS,
            \CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                if (trim($line) !== '') {
                    $headers[] = trim($line);
                }

                return \strlen($line);
            },
        ]);
        $body  = curl_exec($curl);
        $error = curl_error($curl);

        if (!\is_string($body)) {
            throw new PackagerException("Cannot reach GitHub ({$url}): {$error}");
        }

        return [curl_getinfo($curl, \CURLINFO_RESPONSE_CODE), $headers, $body];
    }
}
