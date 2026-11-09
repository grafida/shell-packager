<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Linux;

use Grafida\ShellPackager\Arch;
use Grafida\ShellPackager\Archive\TarWriter;
use Grafida\ShellPackager\Config;
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;

/**
 * The Linux tarball (grafida/grafida#86). For an app whose `linux.name` is `grafida`, whose
 * manifest `id` is `app.grafida` and whose worker is `grafida-worker`:
 *
 * ```
 * grafida/
 *   grafida               the launcher (static): checks the libraries, alerts, execs grafida-shell
 *   grafida-shell         the shell
 *   grafida-worker        the phpmicro sibling-phar SFX stub
 *   grafida-worker.phar   the worker payload (extra-INI block + PHAR)
 *   shell.json            the app's manifest
 *   app.grafida.desktop   the desktop entry, named after the manifest id (= the window's app_id)
 *   icons/hicolor/<n>x<n>/apps/app.grafida.png
 *   install.sh            per-user XDG integration (./install.sh, ./install.sh --uninstall)
 *   …                     linux.files
 * ```
 *
 * The stub and its `.phar` stay two files; they are never recombined (see the README).
 */
final class LinuxTarball
{
    /** The shell's file name; the launcher execs exactly this, beside itself. */
    public const SHELL_BINARY = 'grafida-shell';

    /**
     * @param array{launcher: string, shell: string, sfx: string} $runtimes paths of verified binaries
     */
    public static function write(Config $config, array $runtimes, string $payload, string $output, int $mtime): void
    {
        $top = $config->linuxName;

        foreach ([self::SHELL_BINARY, $config->workerBinary] as $taken) {
            if ($top === $taken) {
                throw new PackagerException("linux.name '{$top}' collides with the {$taken} binary.");
            }
        }

        Filesystem::mkdir(\dirname($output));
        $tar = new TarWriter($output . '.part', $mtime);
        $tar->directory($top);
        $tar->file("{$top}/{$top}", $runtimes['launcher'], 0o755);
        $tar->file("{$top}/" . self::SHELL_BINARY, $runtimes['shell'], 0o755);
        $tar->file("{$top}/{$config->workerBinary}", $runtimes['sfx'], 0o755);
        $tar->file("{$top}/{$config->workerBinary}.phar", $payload, 0o644);
        $tar->file("{$top}/shell.json", $config->manifestPath, 0o644);

        if ($config->desktop !== null) {
            $tar->data("{$top}/{$config->appId}.desktop", self::desktopEntry(Filesystem::read($config->desktop), $config), 0o644);
        }

        if ($config->icons !== []) {
            $tar->directory("{$top}/icons");
            $tar->directory("{$top}/icons/hicolor");

            foreach ($config->icons as $size => $icon) {
                $dir = "{$top}/icons/hicolor/{$size}x{$size}";
                $tar->directory($dir);
                $tar->directory("{$dir}/apps");
                $tar->file("{$dir}/apps/{$config->appId}.png", $icon, 0o644);
            }
        }

        $tar->data("{$top}/install.sh", self::installer($config), 0o755);

        foreach ($config->files as $name => $source) {
            $tar->file("{$top}/{$name}", $source, 0o644);
        }

        $tar->close();
        rename($output . '.part', $output);
        Filesystem::write($output . '.sha256', hash_file('sha256', $output) . '  ' . basename($output) . "\n");
    }

    /** `{name}`, `{version}`, `{arch}` (x86_64/aarch64), `{debarch}` (amd64/arm64), relative to the app. */
    public static function outputPath(Config $config, Arch $arch, string $version): string
    {
        $path = strtr($config->linuxOutput, [
            '{name}'    => $config->linuxName,
            '{version}' => $version,
            '{arch}'    => $arch->value,
            '{debarch}' => $arch->debian(),
        ]);

        return str_starts_with($path, '/') ? $path : $config->root . '/' . $path;
    }

    /**
     * The app's desktop entry, with what must agree with the package forced: `Icon` is the
     * manifest id (installed into the hicolor theme under that name), `StartupWMClass` too (the
     * shell's program name is the id), `Exec` the launcher (the installer makes it absolute), and a
     * missing `Name` becomes the manifest title. Everything else is the app's.
     */
    public static function desktopEntry(string $source, Config $config): string
    {
        $forced = ['Exec' => $config->linuxName, 'Icon' => $config->appId, 'StartupWMClass' => $config->appId];
        $added  = $forced + ['Name' => $config->title, 'Type' => 'Application'];
        $lines  = preg_split('/\r\n|\n|\r/', rtrim($source));
        $out    = [];
        $found  = false;

        foreach (self::sections($lines === false ? [] : $lines) as [$header, $body]) {
            if ($header !== null) {
                $out[] = $header;
            }

            if ($header !== '[Desktop Entry]') {
                array_push($out, ...$body);

                continue;
            }

            $found = true;
            $seen  = [];
            $blank = [];

            // Trailing blank lines separate this section from the next; added keys go before them.
            while ($body !== [] && trim($body[array_key_last($body)]) === '') {
                $blank[] = array_pop($body);
            }

            foreach ($body as $line) {
                if (preg_match('/^([A-Za-z0-9-]+)\s*=/', $line, $m) === 1) {
                    $seen[$m[1]] = true;
                    $line        = isset($forced[$m[1]]) ? $m[1] . '=' . $forced[$m[1]] : $line;
                }

                $out[] = $line;
            }

            foreach ($added as $key => $value) {
                if (!isset($seen[$key])) {
                    $out[] = "{$key}={$value}";
                }
            }

            array_push($out, ...$blank);
        }

        if (!$found) {
            throw new PackagerException('linux.desktop has no [Desktop Entry] section.');
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * Splits a desktop entry into its sections: [header line or null for any preamble, body lines].
     *
     * @param list<string> $lines
     * @return list<array{?string, list<string>}>
     */
    private static function sections(array $lines): array
    {
        $sections = [];
        $header   = null;
        $body     = [];

        foreach ($lines as $line) {
            if (preg_match('/^\[.+]\s*$/', $line) === 1) {
                $sections[] = [$header, $body];
                $header     = rtrim($line);
                $body       = [];

                continue;
            }

            $body[] = $line;
        }

        $sections[] = [$header, $body];

        return $sections;
    }

    public static function installer(Config $config): string
    {
        $template = Filesystem::read(\dirname(__DIR__, 2) . '/resources/linux/install.sh');

        return strtr($template, [
            '@APP_ID@' => self::shellQuote($config->appId),
            '@NAME@'   => self::shellQuote($config->linuxName),
            '@TITLE@'  => self::shellQuote($config->title),
        ]);
    }

    private static function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
