<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager;

use Grafida\ShellPackager\Linux\LinuxTarball;
use Grafida\ShellPackager\Phar\PharBuilder;
use Grafida\ShellPackager\Phar\WorkerCheck;

/** `bin/shell-packager`: argument parsing and the commands. */
final class Cli
{
    public const VERSION = '0.1.0';

    public const USAGE = <<<'TXT'
        shell-packager 0.1.0: packaging for apps on the Grafida desktop shell

        Usage:
          shell-packager linux-tarball --config=FILE --arch=ARCH --app-version=VERSION [options]
          shell-packager phar --config=FILE --output=FILE [--no-check]
          shell-packager fetch --config=FILE --arch=ARCH [--local=NAME=PATH]…
          shell-packager version | help

        Options:
          --config=FILE        the app's packaging configuration (shell-package.json)
          --arch=ARCH          x86_64 or aarch64 (amd64/arm64 accepted)
          --app-version=V      the app's version, for the output file name
          --output=FILE        where to write (linux-tarball: overrides linux.output)
          --local=NAME=PATH    use a local file for runtime NAME (shell, launcher, sfx). UNPINNED:
                               for testing an unreleased build only; announced on every use
          --no-check           skip running the packaged worker (it only runs on a Linux host of
                               the same architecture anyway)

        Timestamps come from SOURCE_DATE_EPOCH when it is set. Downloads are cached by SHA-256 in
        SHELL_PACKAGER_CACHE (default: $XDG_CACHE_HOME/grafida-shell-packager).
        TXT;

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        try {
            $options = self::options(\array_slice($argv, 2));

            return match ($command) {
                'linux-tarball' => self::linuxTarball($options),
                'phar'          => self::phar($options),
                'fetch'         => self::fetch($options),
                'version', '--version' => self::say('shell-packager ' . self::VERSION),
                'help', '--help', '-h' => self::say(self::USAGE),
                default         => throw new PackagerException("Unknown command '{$command}'.\n\n" . self::USAGE),
            };
        } catch (PackagerException $e) {
            fwrite(\STDERR, 'shell-packager: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    /** @param array<string, list<string>> $options */
    private static function linuxTarball(array $options): int
    {
        $config  = Config::load(self::one($options, 'config'));
        $arch    = Arch::parse(self::one($options, 'arch'));
        $version = self::one($options, 'app-version');
        $mtime   = self::timestamp();
        $output  = isset($options['output']) ? self::one($options, 'output') : LinuxTarball::outputPath($config, $arch, $version);
        $fetcher = self::fetcher($options);

        $runtimes = [];

        foreach (['launcher', 'shell', 'sfx'] as $name) {
            $runtimes[$name] = $fetcher->fetch($config->runtime($name), $arch);
            self::say("{$name}: {$runtimes[$name]}");
        }

        $work = Filesystem::tempDir('linux');

        try {
            $payload = $work . '/' . $config->workerBinary . '.phar';
            (new PharBuilder(self::log(...)))->build($config, $payload, $mtime);

            if (!isset($options['no-check'])) {
                self::check($config, $arch, $runtimes['sfx'], $payload);
            }

            LinuxTarball::write($config, $runtimes, $payload, $output, $mtime);
        } finally {
            Filesystem::remove($work);
        }

        self::say("Done: {$output} (" . Filesystem::size((int) filesize($output)) . ')');

        return 0;
    }

    /** @param array<string, list<string>> $options */
    private static function phar(array $options): int
    {
        $config = Config::load(self::one($options, 'config'));
        $output = self::one($options, 'output');
        (new PharBuilder(self::log(...)))->build($config, $output, self::timestamp());
        self::say("Done: {$output}");

        return 0;
    }

    /** @param array<string, list<string>> $options */
    private static function fetch(array $options): int
    {
        $config  = Config::load(self::one($options, 'config'));
        $arch    = Arch::parse(self::one($options, 'arch'));
        $fetcher = self::fetcher($options);

        foreach ($config->runtimes as $runtime) {
            self::say("{$runtime->name}: " . $fetcher->fetch($runtime, $arch));
        }

        return 0;
    }

    private static function check(Config $config, Arch $arch, string $sfx, string $payload): void
    {
        if (!WorkerCheck::canRun($arch)) {
            self::say("Worker check skipped: this machine cannot run a Linux {$arch->value} binary.");

            return;
        }

        WorkerCheck::iniProbe($sfx, $config->ini);
        $header = WorkerCheck::handshake($sfx, $payload, $config->workerBinary);
        self::say('Worker check: the ini block applies, and the packaged worker answered ' . $header);
    }

    /** @param array<string, list<string>> $options */
    private static function fetcher(array $options): Fetcher
    {
        $fetcher = new Fetcher(Fetcher::defaultCacheDir());

        foreach ($options['local'] ?? [] as $spec) {
            [$name, $path] = array_pad(explode('=', $spec, 2), 2, '');

            if ($name === '' || $path === '') {
                throw new PackagerException("--local takes NAME=PATH, got '{$spec}'.");
            }

            $fetcher->useLocal($name, $path);
        }

        return $fetcher;
    }

    private static function timestamp(): int
    {
        $epoch = getenv('SOURCE_DATE_EPOCH');

        if (\is_string($epoch) && $epoch !== '') {
            if (!ctype_digit($epoch)) {
                throw new PackagerException("SOURCE_DATE_EPOCH must be a Unix time, got '{$epoch}'.");
            }

            return (int) $epoch;
        }

        return time();
    }

    /**
     * `--name=value`, `--name value` and bare `--flag`; a name may repeat.
     *
     * @param list<string> $args
     * @return array<string, list<string>>
     */
    private static function options(array $args): array
    {
        $options = [];
        $flags   = ['no-check'];

        for ($i = 0, $n = \count($args); $i < $n; $i++) {
            if (!str_starts_with($args[$i], '--')) {
                throw new PackagerException("Unexpected argument '{$args[$i]}'.");
            }

            $name = substr($args[$i], 2);

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            } elseif (\in_array($name, $flags, true)) {
                $value = '1';
            } else {
                $value = $args[++$i] ?? throw new PackagerException("--{$name} needs a value.");
            }

            $options[$name][] = $value;
        }

        return $options;
    }

    /** @param array<string, list<string>> $options */
    private static function one(array $options, string $name): string
    {
        $values = $options[$name] ?? [];

        if (\count($values) !== 1 || $values[0] === '') {
            throw new PackagerException("--{$name} is required (once).");
        }

        return $values[0];
    }

    private static function log(string $line): void
    {
        self::say($line);
    }

    private static function say(string $line): int
    {
        fwrite(\STDOUT, $line . "\n");

        return 0;
    }
}
