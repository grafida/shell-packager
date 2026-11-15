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
use Grafida\ShellPackager\Config;
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;

/**
 * The Linux `.deb` and `.rpm` (grafida/grafida#87), written by a pinned {@see Nfpm} from the same
 * file list as the tarball ({@see LinuxTarball}). For `linux.name` `grafida` and manifest id
 * `app.grafida`:
 *
 * ```
 * /opt/grafida/                    grafida, grafida-shell, grafida-worker, grafida-worker.phar,
 *                                  shell.json, linux.files
 * /usr/bin/grafida                 symlink to /opt/grafida/grafida (the launcher and the shell
 *                                  resolve their real path, so they find their siblings)
 * /usr/share/applications/app.grafida.desktop
 * /usr/share/icons/hicolor/<n>x<n>/apps/app.grafida.png
 * ```
 *
 * No `install.sh`: the package manager does its job. WebKitGTK and GTK are dependencies, never
 * bundled. The post-install and post-removal scripts refresh the desktop and icon caches, remove a
 * superseded system-wide entry (`linux.replaces`) and, on a final removal, `/opt/<name>` itself.
 */
final class LinuxPackage
{
    public const FORMATS = ['deb', 'rpm'];

    /** The shell's system libraries, per format. They are the shell's, so not repeated in an app's configuration. */
    public const SHELL_DEPENDS = [
        'deb' => ['libwebkit2gtk-4.1-0', 'libgtk-3-0'],
        'rpm' => ['webkit2gtk4.1', 'gtk3'],
    ];

    /**
     * @param array{launcher: string, shell: string, sfx: string} $runtimes paths of verified binaries
     */
    public static function write(
        Config $config,
        string $format,
        array $runtimes,
        string $payload,
        string $output,
        int $mtime,
        Arch $arch,
        string $version,
        string $nfpm,
        string $work,
    ): void {
        $dir = $work . '/' . $format;
        Filesystem::mkdir($dir);
        Filesystem::write($dir . '/nfpm.json', self::configuration($config, $format, $runtimes, $payload, $arch, $version, $dir));
        Filesystem::write($dir . '/postinst.sh', self::script('package-postinst.sh', $config));
        Filesystem::write($dir . '/postrm.sh', self::script('package-postrm.sh', $config));

        Filesystem::mkdir(\dirname($output));
        $partial = $dir . '/out.' . $format;
        $env     = array_merge(getenv(), ['SOURCE_DATE_EPOCH' => (string) $mtime]);
        $process = proc_open(
            [$nfpm, 'package', '--config', $dir . '/nfpm.json', '--packager', $format, '--target', $partial],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $dir,
            $env
        );

        if (!\is_resource($process)) {
            throw new PackagerException('Cannot run nfpm.');
        }

        fclose($pipes[0]);
        $out  = (string) stream_get_contents($pipes[1]);
        $err  = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0 || !is_file($partial)) {
            throw new PackagerException("nfpm failed ({$code}) writing the .{$format}:\n" . trim($err . "\n" . $out));
        }

        rename($partial, $output);
        Filesystem::write($output . '.sha256', hash_file('sha256', $output) . '  ' . basename($output) . "\n");
    }

    /** `{name}`, `{version}`, `{arch}` (x86_64/aarch64), `{debarch}` (amd64/arm64), relative to the app. */
    public static function outputPath(Config $config, string $format, Arch $arch, string $version): string
    {
        $path = strtr($config->linuxPackage()->output[$format] ?? throw new PackagerException("Unknown package format '{$format}'."), [
            '{name}'    => $config->linuxName,
            '{version}' => $version,
            '{arch}'    => $arch->value,
            '{debarch}' => $arch->debian(),
        ]);

        return str_starts_with($path, '/') ? $path : $config->root . '/' . $path;
    }

    /**
     * The version as the package managers want it: a hyphen would start the Debian revision and is
     * not allowed in an RPM version, so `0.5-rc1` becomes `0.5~rc1`, which sorts before `0.5`.
     */
    public static function packageVersion(string $version): string
    {
        $version = str_replace('-', '~', $version);

        if (preg_match('/^[0-9][0-9A-Za-z.+~]*$/', $version) !== 1) {
            throw new PackagerException("--app-version '{$version}' is not a version a .deb or .rpm can carry (it starts with a digit, then letters, digits and . + ~).");
        }

        return $version;
    }

    /**
     * nfpm's configuration (JSON, which is YAML). Built as data and encoded, so no quoting can go
     * wrong; a `$` is refused because nfpm expands environment variables in the whole file.
     *
     * @param array{launcher: string, shell: string, sfx: string} $runtimes
     */
    public static function configuration(Config $config, string $format, array $runtimes, string $payload, Arch $arch, string $version, string $dir): string
    {
        $package = $config->linuxPackage();
        $name    = $config->linuxName;

        if (preg_match('/^[a-z0-9][a-z0-9.+-]+$/', $name) !== 1) {
            throw new PackagerException("linux.name '{$name}' is not a package name (lower-case letters, digits and . + -, at least two characters).");
        }

        foreach ([LinuxTarball::SHELL_BINARY, $config->workerBinary] as $taken) {
            if ($name === $taken) {
                throw new PackagerException("linux.name '{$name}' collides with the {$taken} binary.");
            }
        }

        $root     = '/opt/' . $name;
        $contents = [
            self::dir($root),
            self::file($runtimes['launcher'], "{$root}/{$name}", 0o755),
            self::file($runtimes['shell'], "{$root}/" . LinuxTarball::SHELL_BINARY, 0o755),
            self::file($runtimes['sfx'], "{$root}/{$config->workerBinary}", 0o755),
            self::file($payload, "{$root}/{$config->workerBinary}.phar", 0o644),
            self::file($config->manifestPath, "{$root}/shell.json", 0o644),
            ['src' => "{$root}/{$name}", 'dst' => "/usr/bin/{$name}", 'type' => 'symlink'],
        ];

        if ($config->desktop !== null) {
            $entry = $dir . '/' . $config->appId . '.desktop';
            Filesystem::write($entry, LinuxTarball::desktopEntry(Filesystem::read($config->desktop), $config));
            $contents[] = self::file($entry, "/usr/share/applications/{$config->appId}.desktop", 0o644);
        }

        foreach ($config->icons as $size => $icon) {
            $contents[] = self::file($icon, "/usr/share/icons/hicolor/{$size}x{$size}/apps/{$config->appId}.png", 0o644);
        }

        foreach ($config->files as $file => $source) {
            $contents[] = self::file($source, "{$root}/{$file}", 0o644);
        }

        $depends = array_values(array_unique([...self::SHELL_DEPENDS[$format], ...$package->depends[$format]]));

        $data = [
            'name'        => $name,
            'arch'        => $arch->debian(),
            'platform'    => 'linux',
            'version'     => self::packageVersion($version),
            'version_schema' => 'none',
            'section'     => $package->section,
            'priority'    => 'optional',
            'maintainer'  => $package->maintainer,
            'description' => $package->description ?? $config->title,
            'vendor'      => $package->maintainer,
            'homepage'    => $package->homepage ?? '',
            'license'     => $package->license,
            'depends'     => $depends,
            'contents'    => $contents,
            'scripts'     => ['postinstall' => $dir . '/postinst.sh', 'postremove' => $dir . '/postrm.sh'],
        ];

        if ($format === 'rpm') {
            $data['release'] = '1';
            unset($data['section'], $data['priority'], $data['vendor']);
        }

        $json = json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        if (str_contains($json, '$')) {
            throw new PackagerException('A "$" in the package fields or in a path is not supported (nfpm expands it as an environment variable).');
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private static function file(string $src, string $dst, int $mode): array
    {
        return ['src' => $src, 'dst' => $dst, 'file_info' => ['mode' => $mode, 'owner' => 'root', 'group' => 'root']];
    }

    /** @return array<string, mixed> */
    private static function dir(string $dst): array
    {
        return ['dst' => $dst, 'type' => 'dir', 'file_info' => ['mode' => 0o755, 'owner' => 'root', 'group' => 'root']];
    }

    private static function script(string $resource, Config $config): string
    {
        $template = Filesystem::read(\dirname(__DIR__, 2) . '/resources/linux/' . $resource);

        return strtr($template, [
            '@NAME@'     => self::shellQuote($config->linuxName),
            // Validated desktop ids (no space or glob character), so a plain word list.
            '@REPLACES@' => self::shellQuote(implode(' ', $config->linuxReplaces)),
        ]);
    }

    private static function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
