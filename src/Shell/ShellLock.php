<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Shell;

use Grafida\ShellPackager\Arch;
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Platform;

/**
 * The lock file (`shell-package.lock` beside `shell-package.json`): the release `shell.version`
 * resolved to, and the URL and SHA-256 (GitHub's digest) of all seven shell files. It is written
 * by `shell-packager update` and committed; a build reads it and makes no GitHub API call.
 *
 * `content-hash` is the configuration's ShellConfig::contentHash() when it was written: a lock
 * whose hash differs from the configuration's is stale. The text is fixed (key order, sorted
 * files, pretty-printed, unescaped slashes, a final newline), so an unchanged resolution
 * rewrites it byte for byte. A lock that does not have exactly this shape is refused, never
 * half-read.
 */
final readonly class ShellLock
{
    /** A file or directory name: no separator, and not `.` or `..`. */
    private const string NAME = '/^(?!\.\.?$)[A-Za-z0-9._+-]+$/';

    private const string README = 'Written by shell-packager update. Commit it. Do not edit it by hand.';

    /** @param array<string, array<string, array<string, LockedFile>>> $files platform => component => arch => file */
    public function __construct(
        public string $contentHash,
        public string $repository,
        public string $constraint,
        public string $version,
        public string $tag,
        public array $files,
    ) {
    }

    public static function pathFor(string $configPath): string
    {
        return (str_ends_with($configPath, '.json') ? substr($configPath, 0, -5) : $configPath) . '.lock';
    }

    /** The lock at $path, or null when there is none. */
    public static function read(string $path): ?self
    {
        return is_file($path) ? self::fromJson(Filesystem::read($path), $path) : null;
    }

    public static function fromJson(string $json, string $path): self
    {
        $fail = static fn (string $why): PackagerException => new PackagerException(
            "{$path} is not a valid shell lock file ({$why}). Run shell-packager update to write it again."
        );

        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw $fail('not JSON: ' . $e->getMessage());
        }

        if (!\is_array($data) || !\is_array($data['shell'] ?? null)) {
            throw $fail('no "shell" object');
        }

        $shell = $data['shell'];
        $text  = static function (mixed $value, string $key) use ($fail): string {
            if (!\is_string($value) || $value === '') {
                throw $fail("{$key} is missing");
            }

            return $value;
        };

        $hash = $text($data['content-hash'] ?? null, 'content-hash');

        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw $fail('content-hash is not a SHA-256');
        }

        $files = $shell['files'] ?? null;

        if (!\is_array($files) || array_keys($files) !== array_keys(ShellAssets::SLOTS)) {
            throw $fail('files must name the platforms ' . implode(', ', array_keys(ShellAssets::SLOTS)));
        }

        $out = [];

        foreach (ShellAssets::SLOTS as $platform => $components) {
            if (!\is_array($files[$platform]) || array_keys($files[$platform]) !== array_keys($components)) {
                throw $fail("files.{$platform} must name " . implode(', ', array_keys($components)));
            }

            foreach ($components as $component => $arches) {
                $byArch = $files[$platform][$component];

                if (!\is_array($byArch) || array_keys($byArch) !== $arches) {
                    throw $fail("files.{$platform}.{$component} must name " . implode(', ', $arches));
                }

                foreach ($arches as $arch) {
                    $key  = "files.{$platform}.{$component}.{$arch}";
                    $file = $byArch[$arch];

                    if (!\is_array($file)) {
                        throw $fail("{$key} is not an object");
                    }

                    $sha256 = $text($file['sha256'] ?? null, "{$key}.sha256");

                    if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
                        throw $fail("{$key}.sha256 is not a SHA-256");
                    }

                    $name = $text($file['name'] ?? null, "{$key}.name");

                    if (preg_match(self::NAME, $name) !== 1) {
                        throw $fail("{$key}.name is not a release asset name");
                    }

                    $out[$platform][$component][$arch] = new LockedFile(
                        $name,
                        $text($file['url'] ?? null, "{$key}.url"),
                        $sha256,
                    );
                }
            }
        }

        // The repository, version and names become cache paths: none may climb out of the cache.
        $repository = $text($shell['repository'] ?? null, 'shell.repository');
        $version    = $text($shell['version'] ?? null, 'shell.version');

        if (preg_match('#^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$#', $repository) !== 1 || str_contains($repository, '..')) {
            throw $fail('shell.repository is not owner/name');
        }

        if (preg_match(self::NAME, $version) !== 1) {
            throw $fail('shell.version is not a version');
        }

        return new self(
            $hash,
            $repository,
            $text($shell['constraint'] ?? null, 'shell.constraint'),
            $version,
            $text($shell['tag'] ?? null, 'shell.tag'),
            $out,
        );
    }

    public function toJson(): string
    {
        $files = [];

        foreach ($this->files as $platform => $components) {
            foreach ($components as $component => $byArch) {
                foreach ($byArch as $arch => $file) {
                    $files[$platform][$component][$arch] = ['name' => $file->name, 'url' => $file->url, 'sha256' => $file->sha256];
                }
            }
        }

        return json_encode([
            '_readme'      => self::README,
            'content-hash' => $this->contentHash,
            'shell'        => [
                'repository' => $this->repository,
                'constraint' => $this->constraint,
                'version'    => $this->version,
                'tag'        => $this->tag,
                'files'      => $files,
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
    }

    /** Writes the lock to a temporary file beside $path, then renames it into place. */
    public function write(string $path): void
    {
        $partial = $path . '.' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.part';
        Filesystem::write($partial, $this->toJson());

        if (!@rename($partial, $path)) {
            @unlink($partial);

            throw new PackagerException("Cannot write {$path}.");
        }
    }

    /**
     * Refuses a lock that does not say what the configuration would have resolved to: another
     * repository, other asset names, or a URL that is not that asset of that release on GitHub.
     * The content hash ties a lock to the configuration but not to its files; this ties the files
     * to the repository, so a lock edited by hand cannot send a build elsewhere with a hash to match.
     */
    public function verify(ShellConfig $shell, string $path): void
    {
        $fail = static fn (string $why): PackagerException => new PackagerException(
            "{$path} does not match the configuration ({$why}). Run shell-packager update to write it again."
        );

        if ($this->repository !== $shell->repository) {
            throw $fail("its repository is {$this->repository}, shell.repository is {$shell->repository}");
        }

        foreach ($this->files as $platform => $components) {
            foreach ($components as $component => $byArch) {
                foreach ($byArch as $arch => $file) {
                    $name = $shell->assets[$platform][$component][$arch] ?? null;

                    if ($file->name !== $name) {
                        throw $fail("the {$platform} {$component} for {$arch} is {$file->name}, the configuration names " . json_encode($name));
                    }

                    $url = 'https://github.com/' . $this->repository . '/releases/download/' . rawurlencode($this->tag) . '/' . rawurlencode($file->name);

                    if ($file->url !== $url) {
                        throw $fail("{$file->name} is to be downloaded from {$file->url}, not {$url}");
                    }
                }
            }
        }
    }

    public function file(Platform $platform, string $component, Arch $arch): LockedFile
    {
        return $this->files[$platform->value][$component][$arch->value]
            ?? throw new PackagerException("The shell lock has no {$platform->value} {$component} for {$arch->value}.");
    }
}
