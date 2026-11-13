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

        if ($runtime->latest) {
            return $this->fetchLatest($runtime, $arch);
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

    /**
     * The newest release, which has no pin to check against: it is cached under the hash it turns
     * out to have, and announced as LATEST on stderr every time, with the URL and the hash it
     * had, because the build changes whenever a new release is published.
     */
    private function fetchLatest(Runtime $runtime, Arch $arch): string
    {
        $url = $runtime->url($arch);

        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0o755, true) && !is_dir($this->cacheDir)) {
            throw new PackagerException("Cannot create the cache directory {$this->cacheDir}.");
        }

        $partial   = $this->cacheDir . '/latest-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.part';
        $this->download($url, $partial);
        $hash      = (string) hash_file('sha256', $partial);
        $dir       = $this->cacheDir . '/' . $hash;
        $path      = $dir . '/' . self::basename($url);

        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            @unlink($partial);
            throw new PackagerException("Cannot create the cache directory {$dir}.");
        }

        rename($partial, $path);
        chmod($path, 0o755);

        fwrite(\STDERR, "WARNING: {$runtime->name} for {$arch->value} is LATEST, not pinned: the newest release is used, so this build changes\n"
            . "  when a new one is published, possibly with incompatible changes.\n"
            . "  URL:    {$url}\n  sha256: {$hash}\n"
            . "  To pin what was just used, take the same asset from a specific release and record its sha256.\n");

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
        $ok       = curl_exec($curl);
        $error    = curl_error($curl);
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
