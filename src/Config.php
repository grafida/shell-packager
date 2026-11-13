<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager;

use Grafida\ShellPackager\Macos\MacosConfig;
use Grafida\ShellPackager\Windows\WindowsConfig;

/**
 * An app's packaging configuration (a JSON file in the app, conventionally `shell-package.json`).
 * Every path in it is relative to the file's own directory, the app root. Everything a package is
 * named after comes from the app's shell manifest (`shell.json`): `id`, `title` and
 * `worker.binary`. So the names cannot drift between what the shell expects and what is packaged.
 *
 * ```jsonc
 * {
 *   "manifest": "shell.json",
 *   "phar": {
 *     "entrypoint": "bin/worker.php",
 *     "include": [
 *       {"directory": "assets/private"},
 *       {"directory": "src", "name": "*.php"},
 *       {"directory": "vendor", "name": "*.php", "notName": ["*Test.php"], "notPath": ["phpunit"]}
 *     ],
 *     "ini": {"memory_limit": "512M", "opcache.enable": false}
 *   },
 *   "runtimes": {
 *     "linux": {
 *       "shell":    {"url": "https://…/0.2.0/grafida-shell-linux-{arch}",    "sha256": {"x86_64": "…", "aarch64": "…"}},
 *       "launcher": {"url": "https://…/0.2.0/grafida-launcher-linux-{arch}", "sha256": {…}},
 *       "sfx":      {"url": "https://…/sfx-…/linux-{arch}.standard.sfx",     "sha256": {…}}
 *     },
 *     "macos": {   // a url per architecture when they come from different releases
 *       "shell": {"url": {"aarch64": "https://…/0.2.0/grafida-shell-macos-arm64", "x86_64": "https://…/0.4.0/grafida-shell-macos-x86_64"},
 *                 "sha256": {"aarch64": "…", "x86_64": "…"}},
 *       "sfx":   {"url": "https://…/sfx-…/macos-{arch}.standard.sfx",        "sha256": {"aarch64": "…", "x86_64": "…"}}
 *     }
 *   },
 *   "linux": {
 *     "name": "grafida",
 *     "desktop": "assets/logo/grafida.desktop",
 *     "icons": {"48": "assets/logo/png/grafida-48.png", "512": "…"},
 *     "files": {"LICENSE.txt": "LICENSE.txt"},
 *     "replaces": ["grafida"],   // desktop ids of an earlier build install.sh removes (see below)
 *     "output": "build/dist/Grafida-{version}-linux-{debarch}-shell-preview.tar.gz"
 *   },
 *   "macos": {…},   // see Macos\MacosConfig
 *   "windows": {…}  // see Windows\WindowsConfig
 * }
 * ```
 *
 * `linux.replaces` names the desktop entries of a build this one supersedes under another id (an
 * app's previous packaging; grafida/grafida#92). `install.sh` deletes `<id>.desktop` and that id's
 * hicolor icons from the user's data directory, but only when the entry exists and its `Exec`
 * starts a program called `linux.name`; any other entry with that id is left alone, and said so.
 *
 * `runtimes` is grouped by platform. A flat `runtimes` (names straight under it, as before
 * macOS) still means Linux; mixing the two shapes is an error, never a half-read configuration.
 *
 * Under `vendor`, `include` reads the `--no-dev` staging install, never the app's own `vendor/`.
 */
final readonly class Config
{
    /**
     * @param array<string, bool|int|float|string>                    $ini
     * @param list<IncludeRule>                                       $include
     * @param array<string, array<string, Runtime>>                   $runtimes platform => name => pin
     * @param array<int, string>                                      $icons   size => absolute path
     * @param array<string, string>                                   $files   name in the package => absolute path
     */
    public function __construct(
        public string $root,
        public string $manifestPath,
        public string $appId,
        public string $title,
        public string $workerBinary,
        public string $entrypoint,
        public array $include,
        public array $ini,
        public array $runtimes,
        public string $linuxName,
        public ?string $desktop,
        public array $icons,
        public array $files,
        public string $linuxOutput,
        public ?MacosConfig $macos = null,
        public ?WindowsConfig $windows = null,
        /** @var list<string> desktop ids install.sh replaces */
        public array $linuxReplaces = [],
    ) {
    }

    public static function load(string $path): self
    {
        $real = realpath($path);

        if ($real === false || !is_file($real)) {
            throw new PackagerException("No packaging configuration at {$path}.");
        }

        $root = \dirname($real);
        $data = self::json($real);

        $manifestPath = self::path($root, self::string($data, 'manifest', 'shell.json'), 'manifest');
        $manifest     = self::json($manifestPath);
        $worker       = self::object($manifest, 'worker', $manifestPath);

        $phar    = self::object($data, 'phar', $real);
        $include = [];

        foreach (self::list($phar, 'include', 'phar') as $i => $rule) {
            if (!\is_array($rule)) {
                throw new PackagerException("phar.include[{$i}] must be an object.");
            }

            $include[] = IncludeRule::fromArray($rule, "phar.include[{$i}]");
        }

        $ini = [];

        foreach (self::optionalObject($phar, 'ini') as $key => $value) {
            if (!\is_scalar($value)) {
                throw new PackagerException("phar.ini.{$key} must be a string, number or boolean.");
            }

            $ini[(string) $key] = $value;
        }

        $runtimes = self::runtimes(self::optionalObject($data, 'runtimes'));

        $linux = self::optionalObject($data, 'linux');
        $icons = [];

        foreach (self::optionalObject($linux, 'icons') as $size => $icon) {
            if (!ctype_digit((string) $size) || !\is_string($icon)) {
                throw new PackagerException("linux.icons maps a pixel size to a PNG path; got '{$size}'.");
            }

            $icons[(int) $size] = self::path($root, $icon, "linux.icons.{$size}");
        }

        ksort($icons);
        $files = [];

        foreach (self::optionalObject($linux, 'files') as $name => $file) {
            if (!\is_string($file) || str_contains((string) $name, '..')) {
                throw new PackagerException("linux.files maps a name in the package to a file; got '{$name}'.");
            }

            $files[(string) $name] = self::path($root, $file, "linux.files.{$name}");
        }

        $desktop = isset($linux['desktop']) ? self::path($root, self::string($linux, 'desktop'), 'linux.desktop') : null;
        $appId   = self::string($manifest, 'id', null, $manifestPath);
        $replaces = [];

        if (isset($linux['replaces']) && (!\is_array($linux['replaces']) || !array_is_list($linux['replaces']))) {
            throw new PackagerException('linux.replaces is a list of desktop ids.');
        }

        foreach ($linux['replaces'] ?? [] as $id) {
            // A desktop-file id: what install.sh may put in a path and a glob, nothing else.
            if (!\is_string($id) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id) !== 1) {
                throw new PackagerException('linux.replaces lists desktop ids (letters, digits, . _ -); got ' . json_encode($id) . '.');
            }

            if ($id === $appId) {
                throw new PackagerException("linux.replaces cannot name the app's own id '{$appId}'.");
            }

            $replaces[] = $id;
        }

        return new self(
            root: $root,
            manifestPath: $manifestPath,
            appId: $appId,
            title: self::string($manifest, 'title', null, $manifestPath),
            workerBinary: self::string($worker, 'binary', null, $manifestPath . ' worker'),
            entrypoint: ltrim(self::string($phar, 'entrypoint', null, 'phar'), '/'),
            include: $include,
            ini: $ini,
            runtimes: $runtimes,
            linuxName: self::string($linux, 'name', strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', self::string($manifest, 'title', 'app'))), 'linux'),
            desktop: $desktop,
            icons: $icons,
            files: $files,
            linuxOutput: self::string($linux, 'output', 'build/dist/{name}-{version}-linux-{arch}.tar.gz', 'linux'),
            macos: isset($data['macos']) ? self::parseMacos($root, self::object($data, 'macos', $real), self::string($manifest, 'title', null, $manifestPath)) : null,
            windows: isset($data['windows']) ? self::parseWindows($root, self::object($data, 'windows', $real), self::string($manifest, 'title', null, $manifestPath)) : null,
            linuxReplaces: array_values(array_unique($replaces)),
        );
    }

    public function runtime(string $name, Platform $platform = Platform::Linux): Runtime
    {
        return $this->runtimes[$platform->value][$name]
            ?? throw new PackagerException("The packaging configuration pins no {$platform->value} '{$name}' runtime (runtimes.{$platform->value}.{$name}).");
    }

    public function macos(): MacosConfig
    {
        return $this->macos ?? throw new PackagerException("The packaging configuration has no 'macos' section.");
    }

    public function windows(): WindowsConfig
    {
        return $this->windows ?? throw new PackagerException("The packaging configuration has no 'windows' section.");
    }

    /**
     * `runtimes`, grouped by platform; a flat one is Linux's.
     *
     * @param array<mixed> $raw
     * @return array<string, array<string, Runtime>>
     */
    private static function runtimes(array $raw): array
    {
        $platforms = array_map(static fn (Platform $p): string => $p->value, Platform::cases());
        $grouped   = [];
        $flat      = [];

        foreach ($raw as $key => $value) {
            if (!\is_array($value)) {
                throw new PackagerException("runtimes.{$key} must be an object.");
            }

            if (\in_array((string) $key, $platforms, true) && !\array_key_exists('url', $value)) {
                $grouped[(string) $key] = $value;
            } else {
                $flat[(string) $key] = $value;
            }
        }

        if ($grouped !== [] && $flat !== []) {
            throw new PackagerException(
                'runtimes mixes platform groups (' . implode(', ', array_keys($grouped)) . ') with ungrouped pins ('
                . implode(', ', array_keys($flat)) . '): put every pin under its platform.'
            );
        }

        $out = [];

        foreach ($grouped === [] ? [Platform::Linux->value => $flat] : $grouped as $platform => $pins) {
            foreach ($pins as $name => $runtime) {
                if (!\is_array($runtime)) {
                    throw new PackagerException("runtimes.{$platform}.{$name} must be an object.");
                }

                $out[$platform][(string) $name] = Runtime::fromArray((string) $name, $runtime, $platform);
            }
        }

        return $out;
    }

    /** @param array<mixed> $macos */
    private static function parseMacos(string $root, array $macos, string $title): MacosConfig
    {
        $name = self::string($macos, 'name', $title, 'macos');

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $name) !== 1 || str_ends_with($name, '.app')) {
            throw new PackagerException("macos.name '{$name}' must be a plain file name (letters, digits, space, . _ -), without .app.");
        }

        $entitlements = ['shell' => null, 'worker' => null];

        foreach (self::optionalObject($macos, 'entitlements') as $binary => $file) {
            if (!\array_key_exists((string) $binary, $entitlements) || !\is_string($file)) {
                throw new PackagerException("macos.entitlements maps 'shell' and/or 'worker' to a .plist; got '{$binary}'.");
            }

            $entitlements[(string) $binary] = self::path($root, $file, "macos.entitlements.{$binary}");
        }

        $resources = [];

        foreach (self::optionalObject($macos, 'resources') as $file => $source) {
            if (!\is_string($source) || str_contains((string) $file, '..') || str_starts_with((string) $file, '/')) {
                throw new PackagerException("macos.resources maps a name in Contents/Resources to a file; got '{$file}'.");
            }

            $resources[(string) $file] = self::path($root, $source, "macos.resources.{$file}");
        }

        $infoPlist = [];

        foreach (self::optionalObject($macos, 'infoPlist') as $key => $value) {
            $infoPlist[(string) $key] = $value;
        }

        $dmg       = self::optionalObject($macos, 'dmg');
        $positions = self::optionalObject($dmg, 'positions');
        $optional  = static fn (array $data, string $key, string $what): ?string => isset($data[$key])
            ? self::path($root, self::string($data, $key, null, $what), "{$what}.{$key}")
            : null;

        return new MacosConfig(
            name: $name,
            icon: $optional($macos, 'icon', 'macos'),
            infoPlist: $infoPlist,
            entitlements: $entitlements,
            resources: $resources,
            dmgBackground: $optional($dmg, 'background', 'macos.dmg'),
            window: self::pair($dmg, 'window', [640, 400], 'macos.dmg'),
            iconSize: self::int($dmg, 'iconSize', 128, 'macos.dmg'),
            textSize: self::int($dmg, 'textSize', 13, 'macos.dmg'),
            appPosition: self::pair($positions, 'app', [160, 210], 'macos.dmg.positions'),
            applicationsPosition: self::pair($positions, 'applications', [480, 210], 'macos.dmg.positions'),
            volumeIcon: $optional($dmg, 'volumeIcon', 'macos.dmg'),
            volumeName: self::string($dmg, 'volumeName', $name, 'macos.dmg'),
            appOutput: self::string($macos, 'app', 'build/shell-macos/{name}.app', 'macos'),
            dmgOutput: self::string($macos, 'output', 'build/dist/{name}-{version}-macos-{debarch}.dmg', 'macos'),
        );
    }

    /** @param array<mixed> $windows */
    private static function parseWindows(string $root, array $windows, string $title): WindowsConfig
    {
        $name = self::string($windows, 'name', $title, 'windows');

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $name) !== 1 || str_ends_with(strtolower($name), '.exe') || str_ends_with($name, '.')) {
            throw new PackagerException("windows.name '{$name}' must be a plain file name (letters, digits, space, . _ -), without .exe.");
        }

        $version = self::optionalObject($windows, 'version');

        foreach (array_keys($version) as $key) {
            if (!\in_array($key, ['companyName', 'legalCopyright', 'productName', 'shellDescription', 'workerDescription'], true)) {
                throw new PackagerException("windows.version.{$key} is not a version string the packager writes.");
            }
        }

        $files = [];

        foreach (self::optionalObject($windows, 'files') as $file => $source) {
            if (!\is_string($source) || str_contains((string) $file, '..') || str_starts_with((string) $file, '/') || str_contains((string) $file, '\\')) {
                throw new PackagerException("windows.files maps a name in the tree to a file; got '{$file}'.");
            }

            $files[(string) $file] = self::path($root, $source, "windows.files.{$file}");
        }

        $installer = self::optionalObject($windows, 'installer');
        $defines   = [];

        foreach (self::optionalObject($installer, 'defines') as $key => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) !== 1 || !\is_scalar($value)) {
                throw new PackagerException("windows.installer.defines.{$key}: a define is a name (letters, digits, _) and a string.");
            }

            $defines[(string) $key] = (string) $value;
        }

        $reserved = array_intersect(array_keys($defines), \Grafida\ShellPackager\Windows\WindowsInstaller::DEFINES);

        if ($reserved !== []) {
            throw new PackagerException('windows.installer.defines may not set ' . implode(', ', $reserved) . ': the packager passes those.');
        }

        $text = static fn (string $key, string $default): string => self::string($version, $key, $default, 'windows.version');

        return new WindowsConfig(
            name: $name,
            icon: isset($windows['icon']) ? self::path($root, self::string($windows, 'icon', null, 'windows'), 'windows.icon') : null,
            companyName: $text('companyName', $name),
            legalCopyright: isset($version['legalCopyright']) ? $text('legalCopyright', '') : '',
            productName: $text('productName', $name),
            shellDescription: $text('shellDescription', $name),
            workerDescription: $text('workerDescription', $name . ' worker'),
            files: $files,
            installerScript: isset($installer['script']) ? self::path($root, self::string($installer, 'script', null, 'windows.installer'), 'windows.installer.script') : null,
            defines: $defines,
            treeOutput: self::string($windows, 'tree', 'build/shell-windows/{name}', 'windows'),
            installerOutput: self::string($windows, 'output', 'build/dist/{name}-{version}-windows-{debarch}-Setup.exe', 'windows'),
        );
    }

    /**
     * @param array<mixed> $data
     * @param array{int, int} $default
     * @return array{int, int}
     */
    private static function pair(array $data, string $key, array $default, string $where): array
    {
        $value = $data[$key] ?? $default;

        if (!\is_array($value) || !array_is_list($value) || \count($value) !== 2 || !\is_int($value[0]) || !\is_int($value[1]) || $value[0] < 0 || $value[1] < 0) {
            throw new PackagerException("{$where}: '{$key}' must be two non-negative integers.");
        }

        return [$value[0], $value[1]];
    }

    /** @param array<mixed> $data */
    private static function int(array $data, string $key, int $default, string $where): int
    {
        $value = $data[$key] ?? $default;

        if (!\is_int($value) || $value <= 0) {
            throw new PackagerException("{$where}: '{$key}' must be a positive integer.");
        }

        return $value;
    }

    /** @return array<mixed> */
    private static function json(string $path): array
    {
        $text = @file_get_contents($path);

        if ($text === false) {
            throw new PackagerException("Cannot read {$path}.");
        }

        try {
            $data = json_decode($text, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PackagerException("{$path} is not valid JSON: {$e->getMessage()}");
        }

        if (!\is_array($data)) {
            throw new PackagerException("{$path} must hold a JSON object.");
        }

        return $data;
    }

    /** @param array<mixed> $data */
    private static function string(array $data, string $key, ?string $default = null, string $where = 'configuration'): string
    {
        $value = $data[$key] ?? $default;

        if (!\is_string($value) || $value === '') {
            throw new PackagerException("{$where}: '{$key}' must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function object(array $data, string $key, string $where): array
    {
        $value = $data[$key] ?? null;

        if (!\is_array($value)) {
            throw new PackagerException("{$where}: '{$key}' must be an object.");
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function optionalObject(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (!\is_array($value)) {
            throw new PackagerException("'{$key}' must be an object.");
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return list<mixed>
     */
    private static function list(array $data, string $key, string $where): array
    {
        $value = $data[$key] ?? null;

        if (!\is_array($value) || !array_is_list($value) || $value === []) {
            throw new PackagerException("{$where}: '{$key}' must be a non-empty list.");
        }

        return $value;
    }

    private static function path(string $root, string $relative, string $what): string
    {
        // Absolute: /…, or on a Windows host C:\… or C:/… (the packager runs there for the worker check).
        $absolute = str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $relative) === 1;
        $path     = $absolute ? $relative : $root . '/' . $relative;

        if (!file_exists($path)) {
            throw new PackagerException("{$what}: {$path} does not exist.");
        }

        return $path;
    }
}
