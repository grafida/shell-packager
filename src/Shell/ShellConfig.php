<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Shell;

use Composer\Semver\VersionParser;
use Grafida\ShellPackager\Arch;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Platform;

/**
 * The configuration's `shell` block: the shell and launcher pinned by a Composer version
 * constraint against a public GitHub repository's releases, resolved into the lock file by
 * `shell-packager update`.
 *
 * ```jsonc
 * "shell": {
 *   "version": "^0.4.2",                 // required: a Composer version constraint
 *   "repository": "grafida/shell",       // optional: owner/name on github.com (public)
 *   "assets": {"linux": {"shell": "…-{arch}"}},           // optional: names, over ShellAssets::DEFAULTS
 *   "local": {"macos": {"shell": {"aarch64": "../…"}}}    // optional: files standing in for assets
 * }
 * ```
 *
 * Defaults are merged per platform and component. `local` paths are relative to the configuration
 * file, may name any subset of the files, and never affect the lock: the content hash leaves them out.
 */
final readonly class ShellConfig
{
    public const string DEFAULT_REPOSITORY = 'grafida/shell';

    /**
     * @param array<string, array<string, array<string, string>>> $assets platform => component => arch => asset name (all seven)
     * @param array<string, array<string, array<string, string>>> $local  platform => component => arch => absolute path (any)
     */
    public function __construct(
        public string $repository,
        public string $constraint,
        public array $assets,
        public array $local = [],
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data, string $root): self
    {
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, ['version', 'repository', 'assets', 'local'], true)) {
                throw new PackagerException("shell.{$key}: unknown key (version, repository, assets, local).");
            }
        }

        $constraint = $data['version'] ?? null;

        if (!\is_string($constraint) || trim($constraint) === '') {
            throw new PackagerException('shell.version is required: a Composer version constraint such as "^0.4" or "0.4.2".');
        }

        try {
            (new VersionParser())->parseConstraints($constraint);
        } catch (\UnexpectedValueException $e) {
            throw new PackagerException("shell.version '{$constraint}' is not a Composer version constraint: " . $e->getMessage());
        }

        $repository = $data['repository'] ?? self::DEFAULT_REPOSITORY;

        if (!\is_string($repository) || preg_match('#^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$#', $repository) !== 1) {
            throw new PackagerException('shell.repository must be a GitHub repository as owner/name, got ' . json_encode($repository) . '.');
        }

        $assets = self::section($data, 'assets');
        $names  = ShellAssets::expand(ShellAssets::DEFAULTS, 'shell.assets', false);

        foreach (ShellAssets::expand($assets, 'shell.assets', false) as $platform => $components) {
            foreach ($components as $component => $byArch) {
                foreach ($byArch as $arch => $name) {
                    if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
                        throw new PackagerException("shell.assets.{$platform}.{$component}.{$arch}: '{$name}' is not a release asset name.");
                    }
                }

                $names[$platform][$component] = $byArch;
            }
        }

        $local = ShellAssets::expand(self::section($data, 'local'), 'shell.local', true);

        foreach ($local as $platform => $components) {
            foreach ($components as $component => $byArch) {
                foreach ($byArch as $arch => $path) {
                    $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
                    $full     = $absolute ? $path : $root . '/' . $path;

                    if (!is_file($full)) {
                        throw new PackagerException("shell.local.{$platform}.{$component}.{$arch}: {$full} is not a file.");
                    }

                    $local[$platform][$component][$arch] = $full;
                }
            }
        }

        return new self($repository, $constraint, self::sorted($names), $local);
    }

    /**
     * What the lock is valid for: the repository, the constraint and the effective asset names.
     * Local files are left out, so adding or removing one never makes the lock stale.
     */
    public function contentHash(): string
    {
        return hash('sha256', json_encode(
            ['repository' => $this->repository, 'version' => $this->constraint, 'assets' => $this->assets],
            \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR
        ));
    }

    public function asset(Platform $platform, string $component, Arch $arch): string
    {
        return $this->assets[$platform->value][$component][$arch->value]
            ?? throw new PackagerException("The shell has no {$platform->value} {$component} for {$arch->value}.");
    }

    public function local(Platform $platform, string $component, Arch $arch): ?string
    {
        return $this->local[$platform->value][$component][$arch->value] ?? null;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function section(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (!\is_array($value)) {
            throw new PackagerException("shell.{$key} must be an object.");
        }

        return $value;
    }

    /**
     * @param array<string, array<string, array<string, string>>> $names
     * @return array<string, array<string, array<string, string>>>
     */
    private static function sorted(array $names): array
    {
        ksort($names);

        foreach ($names as &$components) {
            ksort($components);

            foreach ($components as &$byArch) {
                ksort($byArch);
            }
        }

        return $names;
    }
}
