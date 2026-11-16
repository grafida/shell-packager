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
use Grafida\ShellPackager\Config;
use Grafida\ShellPackager\Fetcher;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Platform;

/**
 * Where each shell file of a build comes from, in this order: a `--local` file, a `shell.local`
 * file (both announced as UNPINNED), or the lock beside the configuration. A build from the lock
 * makes no GitHub API call. With no lock, the first file that needs one resolves and writes it,
 * as `update` does; a lock that no longer matches the configuration fails the build, and so does
 * one whose files are not the configured repository's release assets (ShellLock::verify()).
 */
final class ShellFiles
{
    private ?ShellLock $lock = null;

    /**
     * @param \Closure(): ReleaseSource $source how releases are listed; called only to resolve
     * @param \Closure(string): void    $say    progress for the user
     */
    public function __construct(
        private readonly Config $config,
        private readonly Fetcher $fetcher,
        private readonly \Closure $source,
        private readonly \Closure $say,
    ) {
    }

    public function path(Platform $platform, string $component, Arch $arch): string
    {
        $shell = $this->config->shell();

        if ($this->fetcher->hasLocal($component)) {
            return $this->fetcher->local($component);
        }

        $local = $shell->local($platform, $component, $arch);

        if ($local !== null) {
            return Fetcher::unpinned("{$component} ({$platform->value} {$arch->value})", $local);
        }

        $lock = $this->lock();

        return $this->fetcher->fetchLocked($lock->file($platform, $component, $arch), $lock->repository, $lock->version);
    }

    /**
     * Resolves shell.version against the repository's releases, downloads all seven files
     * (each checked against GitHub's digest) and only then writes the lock.
     */
    public function update(): ShellLock
    {
        $shell = $this->config->shell();
        $path  = ShellLock::pathFor($this->config->path);

        try {
            $old = ShellLock::read($path);
        } catch (PackagerException) {
            $old = null; // a broken lock is what update replaces
        }

        $source = ($this->source)();
        ($this->say)("Resolving shell.version '{$shell->constraint}' against the releases of {$shell->repository} (through {$source->describe()})");
        $lock = Resolver::resolve($shell, $source->releases($shell->repository));
        // What a build will check the lock against, before anything is downloaded or written.
        $lock->verify($shell, $path);

        foreach ($lock->files as $components) {
            foreach ($components as $byArch) {
                foreach ($byArch as $file) {
                    $this->fetcher->fetchLocked($file, $lock->repository, $lock->version);
                }
            }
        }

        $lock->write($path);

        ($this->say)(match (true) {
            $old === null                         => "shell {$lock->version} (tag {$lock->tag})",
            $old->toJson() === $lock->toJson()    => "shell {$lock->version} (unchanged)",
            default                               => "shell {$old->version} → {$lock->version} (tag {$lock->tag})",
        });

        foreach ($lock->files as $components) {
            foreach ($components as $byArch) {
                foreach ($byArch as $file) {
                    ($this->say)("  {$file->name} {$file->sha256}");
                }
            }
        }

        return $this->lock = $lock;
    }

    private function lock(): ShellLock
    {
        if ($this->lock !== null) {
            return $this->lock;
        }

        $path = ShellLock::pathFor($this->config->path);
        $lock = ShellLock::read($path);

        if ($lock === null) {
            $lock = $this->update();
            ($this->say)("Wrote {$path}: commit it.");

            return $lock;
        }

        $shell = $this->config->shell();

        if ($lock->contentHash !== $shell->contentHash()) {
            throw new PackagerException(
                "{$path} is stale: shell.version, shell.repository or shell.assets changed since it was written. "
                . "Run shell-packager update --config={$this->config->path} to resolve the shell again."
            );
        }

        $lock->verify($shell, $path);

        return $this->lock = $lock;
    }
}
