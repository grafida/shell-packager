<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Phar;

use Grafida\ShellPackager\Config;
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;
use Seld\PharUtils\Timestamps;

/**
 * Builds the worker payload: the app's PHAR, behind phpmicro's extra-INI block, ready to sit beside
 * the SFX stub as `<worker>.phar`.
 *
 * - **Dev dependencies never ship.** Files under `vendor/` come from a staging copy of the app's
 *   `composer.json` + `composer.lock` installed with `--no-dev --no-scripts`, never from the app's
 *   own `vendor/`. A relative `path` repository is made absolute in the staged `composer.json`.
 * - **Deterministic:** entries are added in sorted order, every timestamp is `$timestamp`
 *   (`SOURCE_DATE_EPOCH`), and the signature is SHA-256, so one tree builds one byte sequence.
 * - **Uncompressed**, as a worker reads its own files at start-up and a tarball compresses anyway.
 *
 * The stub maps the PHAR under the alias `<worker>.phar` and requires the entry point from it.
 */
final class PharBuilder
{
    /** @var \Closure(string): void */
    private \Closure $log;

    /** @param (callable(string): void)|null $log */
    public function __construct(?callable $log = null, private readonly string $composer = 'composer')
    {
        $this->log = $log === null ? static function (string $line): void {} : $log(...);
    }

    /**
     * Writes the payload to $output and returns the number of files in the PHAR.
     */
    public function build(Config $config, string $output, int $timestamp): int
    {
        if ((bool) \ini_get('phar.readonly')) {
            throw new PackagerException('phar.readonly is on: run PHP with -d phar.readonly=0.');
        }

        $work = Filesystem::tempDir('phar');

        try {
            $staging = null;

            foreach ($config->include as $rule) {
                if ($rule->isVendor()) {
                    $staging ??= $this->stageVendor($config->root, $work . '/stage');
                }
            }

            $files = $this->collect($config, $staging);

            if (!isset($files[$config->entrypoint])) {
                throw new PackagerException("The entry point {$config->entrypoint} is not among the included files.");
            }

            $alias = $config->workerBinary . '.phar';
            $phar  = $work . '/' . $alias;
            $this->writePhar($phar, $alias, $files, $config->entrypoint, $config->title);

            $timestamps = new Timestamps($phar);
            $timestamps->updateTimestamps($timestamp);
            $timestamps->save($phar, \Phar::SHA256);

            $payload = IniBlock::encode($config->ini) . Filesystem::read($phar);
            Filesystem::write($output, $payload);
            ($this->log)(\sprintf('PHAR: %d files, %s, ini %s', \count($files), Filesystem::size(\strlen($payload)), $config->ini === [] ? 'none' : implode(', ', array_keys($config->ini))));

            return \count($files);
        } finally {
            Filesystem::remove($work);
        }
    }

    /**
     * The files to pack: name inside the PHAR => path on disk, sorted by name.
     *
     * @return array<string, string>
     */
    public function collect(Config $config, ?string $staging): array
    {
        $files = [];

        foreach ($config->include as $i => $rule) {
            $base = $rule->isVendor() ? $staging : $config->root;

            if ($base === null) {
                throw new PackagerException("phar.include[{$i}]: vendor files need the staging install.");
            }

            $dir = $base . '/' . $rule->directory;

            if (is_file($dir)) {
                $files[$rule->directory] = $dir;

                continue;
            }

            if (!is_dir($dir)) {
                throw new PackagerException("phar.include[{$i}]: {$rule->directory} does not exist" . ($rule->isVendor() ? ' in the --no-dev install.' : '.'));
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($dir) + 1));

                if ($rule->accepts($relative)) {
                    $files[$rule->directory . '/' . $relative] = $file->getPathname();
                }
            }
        }

        ksort($files, \SORT_STRING);

        return $files;
    }

    /** Installs the app's runtime dependencies into $stage; returns $stage. */
    private function stageVendor(string $root, string $stage): string
    {
        $lock = $root . '/composer.lock';

        if (!is_file($root . '/composer.json') || !is_file($lock)) {
            throw new PackagerException("Vendor files are included, so {$root} needs composer.json and composer.lock.");
        }

        Filesystem::mkdir($stage);
        $json = json_decode(Filesystem::read($root . '/composer.json'), true);

        if (!\is_array($json)) {
            throw new PackagerException("{$root}/composer.json is not a JSON object.");
        }

        if (\is_array($json['repositories'] ?? null)) {
            $json['repositories'] = array_map(static fn (mixed $r): mixed => self::copied(self::absolutePath($r, $root)), $json['repositories']);
        }

        Filesystem::write($stage . '/composer.json', (string) json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        // The lock records a path package's dist URL as written, relative to the app: make it absolute
        // too, since the install reads the lock, not composer.json.
        $locked = json_decode(Filesystem::read($lock), true);

        if (!\is_array($locked)) {
            throw new PackagerException("{$lock} is not a JSON object.");
        }

        foreach (['packages', 'packages-dev'] as $section) {
            if (\is_array($locked[$section] ?? null)) {
                $locked[$section] = array_map(static function (mixed $package) use ($root): mixed {
                    if (\is_array($package) && isset($package['dist'])) {
                        $package['dist'] = self::absolutePath($package['dist'], $root);

                        // An install from a lock takes a path package's options from here, not from
                        // composer.json's repository.
                        if (\is_array($package['dist']) && ($package['dist']['type'] ?? null) === 'path') {
                            $options                       = \is_array($package['transport-options'] ?? null) ? $package['transport-options'] : [];
                            $package['transport-options'] = array_replace($options, ['symlink' => false]);
                        }
                    }

                    return $package;
                }, $locked[$section]);
            }
        }

        Filesystem::write($stage . '/composer.lock', (string) json_encode($locked, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        ($this->log)('Installing the runtime dependencies (composer install --no-dev)…');
        $command = [$this->composer, 'install', '--no-dev', '--no-scripts', '--no-interaction', '--no-progress', '--prefer-dist', '--working-dir=' . $stage];

        if (\PHP_OS_FAMILY === 'Windows') {
            // As a command string, run by cmd.exe: CreateProcess (the array form) runs no
            // composer.bat named without its extension, and a batch file found on PATH under a
            // quoted name gets the wrong %~dp0 (the working directory), which composer.bat
            // wrappers use to find composer.phar. So the name goes unquoted, its arguments quoted.
            $command = $this->composer . ' ' . implode(' ', array_map(escapeshellarg(...), \array_slice($command, 1)));
        }

        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!\is_resource($process)) {
            throw new PackagerException("Cannot run {$this->composer}.");
        }

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new PackagerException("composer install --no-dev failed:\n" . trim($out . "\n" . $err));
        }

        return $stage;
    }

    /** A `{"type": "path", "url": "../x"}` entry, with its URL made absolute against $root. */
    private static function absolutePath(mixed $entry, string $root): mixed
    {
        if (!\is_array($entry) || ($entry['type'] ?? null) !== 'path') {
            return $entry;
        }

        $url = $entry['url'] ?? null;

        if (\is_string($url) && !str_starts_with($url, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $url) !== 1) {
            $entry['url'] = $root . '/' . $url;
        }

        return $entry;
    }

    /**
     * A path repository installed as a copy, never a link: on Windows composer links one with a
     * junction, which RecursiveDirectoryIterator presents as a leaf and never enters, so the
     * package went missing from the payload. A copy holds the same files under the same names on
     * every host. (The lock's `transport-options` are set too: an install from a lock reads those.)
     */
    private static function copied(mixed $entry): mixed
    {
        if (\is_array($entry) && ($entry['type'] ?? null) === 'path') {
            $options          = \is_array($entry['options'] ?? null) ? $entry['options'] : [];
            $entry['options'] = array_replace($options, ['symlink' => false]);
        }

        return $entry;
    }

    /** @param array<string, string> $files */
    private function writePhar(string $path, string $alias, array $files, string $entrypoint, string $title): void
    {
        $phar = new \Phar($path, 0, $alias);
        $phar->startBuffering();

        // One call, never addFile() per file: in a buffered PHAR each addFile() still costs time
        // that grows with the entries already added, so Grafida's 1,690 files took 25 s against
        // 0.1 s, for the same entries in the same (sorted) order and the same bytes.
        $phar->buildFromIterator(new \ArrayIterator($files));

        $comment = str_replace(['*/', "\n", "\r"], '', $title);
        $phar->setStub(<<<PHP
            <?php
            /* {$comment}: the shell worker, built by grafida/shell-packager. */
            Phar::mapPhar('{$alias}');
            require 'phar://{$alias}/{$entrypoint}';
            __HALT_COMPILER();
            PHP);
        $phar->setSignatureAlgorithm(\Phar::SHA256);
        $phar->stopBuffering();
        unset($phar);
    }
}
