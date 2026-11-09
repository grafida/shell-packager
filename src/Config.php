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
 *     "shell":    {"url": "https://…/0.1.0/grafida-shell-linux-{arch}",    "sha256": {"x86_64": "…", "aarch64": "…"}},
 *     "launcher": {"url": "https://…/0.1.0/grafida-launcher-linux-{arch}", "sha256": {…}},
 *     "sfx":      {"url": "https://…/sfx-…/linux-{arch}.standard.sfx",     "sha256": {…}}
 *   },
 *   "linux": {
 *     "name": "grafida",
 *     "desktop": "assets/logo/grafida.desktop",
 *     "icons": {"48": "assets/logo/png/grafida-48.png", "512": "…"},
 *     "files": {"LICENSE.txt": "LICENSE.txt"},
 *     "output": "build/dist/Grafida-{version}-linux-{debarch}-shell-preview.tar.gz"
 *   }
 * }
 * ```
 *
 * Under `vendor`, `include` reads the `--no-dev` staging install, never the app's own `vendor/`.
 */
final readonly class Config
{
    /**
     * @param array<string, bool|int|float|string>                    $ini
     * @param list<IncludeRule>                                       $include
     * @param array<string, Runtime>                                  $runtimes
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

        $runtimes = [];

        foreach (self::optionalObject($data, 'runtimes') as $name => $runtime) {
            if (!\is_array($runtime)) {
                throw new PackagerException("runtimes.{$name} must be an object.");
            }

            $runtimes[(string) $name] = Runtime::fromArray((string) $name, $runtime);
        }

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
        );
    }

    public function runtime(string $name): Runtime
    {
        return $this->runtimes[$name]
            ?? throw new PackagerException("The packaging configuration pins no '{$name}' runtime (runtimes.{$name}).");
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
