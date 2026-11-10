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
 *     "macos": {
 *       "shell": {"url": "https://…/0.2.0/grafida-shell-macos-arm64",         "sha256": {"aarch64": "…"}},
 *       "sfx":   {"url": "https://…/sfx-…/macos-aarch64.standard.sfx",        "sha256": {"aarch64": "…"}}
 *     }
 *   },
 *   "linux": {
 *     "name": "grafida",
 *     "desktop": "assets/logo/grafida.desktop",
 *     "icons": {"48": "assets/logo/png/grafida-48.png", "512": "…"},
 *     "files": {"LICENSE.txt": "LICENSE.txt"},
 *     "output": "build/dist/Grafida-{version}-linux-{debarch}-shell-preview.tar.gz"
 *   },
 *   "macos": {…}    // see Macos\MacosConfig
 * }
 * ```
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

                $out[$platform][(string) $name] = Runtime::fromArray((string) $name, $runtime);
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
        $path = str_starts_with($relative, '/') ? $relative : $root . '/' . $relative;

        if (!file_exists($path)) {
            throw new PackagerException("{$what}: {$path} does not exist.");
        }

        return $path;
    }
}
