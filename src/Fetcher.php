<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager;

/**
 * Downloads a pinned runtime into a cache keyed by its SHA-256, and refuses anything whose hash is
 * not the pinned one. A cached file is re-hashed on every use, so a damaged or replaced cache entry
 * is noticed. There is no "no hash, skip the check" path.
 *
 * The cache is per user (`$XDG_CACHE_HOME/grafida-shell-packager`, or `SHELL_PACKAGER_CACHE`) and
 * deliberately outside the app: an app's own build directories may be read by other tooling (in
 * Grafida, `build/sfx/` is injected into the Boson build).
 *
 * A local file may stand in for a runtime (`--local shell=PATH`), for testing an unreleased build.
 * It is announced as UNPINNED on stderr, every time.
 */
final class Fetcher
{
    /** @var array<string, string> runtime name => local file */
    private array $local = [];

    public function __construct(private readonly string $cacheDir)
    {
    }

    public static function defaultCacheDir(): string
    {
        $explicit = getenv('SHELL_PACKAGER_CACHE');

        if (\is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $xdg  = getenv('XDG_CACHE_HOME');
        $home = getenv('HOME');
        $base = \is_string($xdg) && $xdg !== '' ? $xdg : (\is_string($home) && $home !== '' ? $home . '/.cache' : sys_get_temp_dir());

        return $base . '/grafida-shell-packager';
    }

    public function useLocal(string $runtime, string $path): void
    {
        if (!is_file($path)) {
            throw new PackagerException("--local {$runtime}: {$path} is not a file.");
        }

        $this->local[$runtime] = $path;
    }

    /** Returns the path of a verified copy of $runtime for $arch. */
    public function fetch(Runtime $runtime, Arch $arch): string
    {
        if (isset($this->local[$runtime->name])) {
            $path = $this->local[$runtime->name];
            fwrite(\STDERR, "WARNING: {$runtime->name} is UNPINNED: using the local file {$path} (sha256 " . hash_file('sha256', $path) . ").\n");

            return $path;
        }

        $pin  = $runtime->pin($arch);
        $url  = $runtime->url($arch);
        $dir  = $this->cacheDir . '/' . $pin;
        $path = $dir . '/' . self::basename($url);

        if (is_file($path)) {
            if (hash_file('sha256', $path) === $pin) {
                return $path;
            }

            @unlink($path);
        }

        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new PackagerException("Cannot create the cache directory {$dir}.");
        }

        $partial = $path . '.part';
        $this->download($url, $partial);
        $actual = hash_file('sha256', $partial);

        if ($actual !== $pin) {
            @unlink($partial);

            throw new PackagerException(
                "{$runtime->name} for {$arch->value} does not match its pin.\n  URL:      {$url}\n  expected: {$pin}\n  got:      {$actual}"
            );
        }

        rename($partial, $path);
        chmod($path, 0o755);

        return $path;
    }

    private function download(string $url, string $to): void
    {
        $out = fopen($to, 'wb');

        if ($out === false) {
            throw new PackagerException("Cannot write {$to}.");
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            \CURLOPT_FILE           => $out,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS      => 10,
            \CURLOPT_FAILONERROR    => true,
            \CURLOPT_CONNECTTIMEOUT => 30,
            \CURLOPT_USERAGENT      => 'grafida-shell-packager',
            \CURLOPT_PROTOCOLS      => \CURLPROTO_HTTPS | \CURLPROTO_FILE,
        ]);
        $ok    = curl_exec($curl);
        $error = curl_error($curl);
        fclose($out);

        if ($ok !== true) {
            @unlink($to);

            throw new PackagerException("Download failed: {$url}: {$error}");
        }
    }

    private static function basename(string $url): string
    {
        $path = parse_url($url, \PHP_URL_PATH);

        return basename(\is_string($path) && $path !== '' ? $path : 'download');
    }
}
