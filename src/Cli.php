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
use Grafida\ShellPackager\Macos\Codesign;
use Grafida\ShellPackager\Macos\MacApp;
use Grafida\ShellPackager\Macos\MacDmg;
use Grafida\ShellPackager\Phar\PharBuilder;
use Grafida\ShellPackager\Phar\WorkerCheck;
use Grafida\ShellPackager\Windows\Authenticode;
use Grafida\ShellPackager\Windows\WindowsInstaller;
use Grafida\ShellPackager\Windows\WindowsTree;

/** `bin/shell-packager`: argument parsing and the commands. */
final class Cli
{
    public const VERSION = '0.3.0';

    public const USAGE = <<<'TXT'
        shell-packager 0.3.0: packaging for apps on the Grafida desktop shell

        Usage:
          shell-packager linux-tarball --config=FILE --arch=ARCH --app-version=VERSION [options]
          shell-packager macos-app --config=FILE --app-version=VERSION [options]
          shell-packager macos-dmg --config=FILE --app-version=VERSION [--app=APP] [--no-style] [options]
          shell-packager windows-tree --config=FILE --app-version=VERSION [--output=DIR] [options]
          shell-packager windows-installer --config=FILE --app-version=VERSION [--output=FILE] [options]
          shell-packager windows-sign --file=FILE [--overlay]
          shell-packager phar --config=FILE --output=FILE [--no-check]
          shell-packager fetch --config=FILE --arch=ARCH [--platform=linux|macos|windows] [--local=NAME=PATH]…
          shell-packager version | help

        Options:
          --config=FILE        the app's packaging configuration (shell-package.json)
          --arch=ARCH          x86_64 or aarch64 (amd64/arm64 accepted); macOS is aarch64 only,
                               Windows x86_64 only
          --app-version=V      the app's version (macOS: one to three dot-separated integers)
          --output=FILE        where to write (overrides linux.output, macos.app, macos.output,
                               windows.tree or windows.output)
          --app=APP            macos-dmg: image this signed bundle instead of building one
          --no-style           macos-dmg: no Finder layout (it needs Finder and its Automation
                               permission; a CI runner has neither)
          --local=NAME=PATH    use a local file for runtime NAME (shell, launcher, sfx). UNPINNED:
                               for testing an unreleased build only; announced on every use
          --no-check           skip running the packaged worker (it runs only on a host of the
                               target's OS and architecture anyway)
          --file=FILE          windows-sign: the executable to sign (what !uninstfinalize runs)
          --overlay            windows-sign: the file keeps data past its image (an NSIS installer
                               or uninstaller); never for a worker stub

        macOS signing comes from the environment, never the configuration: MACOS_SIGN_IDENTITY (a
        Developer ID Application identity; without it, ad hoc) and MACOS_NOTARY_PROFILE (a
        notarytool keychain profile; needs the identity). Every binary gets the hardened runtime.

        Windows signing comes from the environment too: Trusted Signing with WINDOWS_SIGN_ENDPOINT,
        WINDOWS_SIGN_PROFILE (account/profile) and WINDOWS_SIGN_TOKEN (an access token), or a test
        keystore with WINDOWS_SIGN_PKCS12 and WINDOWS_SIGN_PKCS12_PASSWORD. Neither: unsigned.
        WINDOWS_SIGN_CAFILE makes the signature checks verify the chain to those roots. Needs
        jsign and osslsigncode; the installer needs makensis 3.08 or later.

        Timestamps come from SOURCE_DATE_EPOCH when it is set (Linux output is byte-reproducible;
        macOS output is not). Downloads are cached by SHA-256 in SHELL_PACKAGER_CACHE (default:
        $XDG_CACHE_HOME/grafida-shell-packager).
        TXT;

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        try {
            $options = self::options(\array_slice($argv, 2));

            return match ($command) {
                'linux-tarball' => self::linuxTarball($options),
                'macos-app'     => self::macosApp($options),
                'macos-dmg'     => self::macosDmg($options),
                'windows-tree'  => self::windowsTree($options),
                'windows-installer' => self::windowsInstaller($options),
                'windows-sign'  => self::windowsSign($options),
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
    private static function macosApp(array $options): int
    {
        $config = Config::load(self::one($options, 'config'));
        $app    = isset($options['output']) ? self::one($options, 'output') : MacApp::outputPath($config);
        self::buildApp($config, $options, $app);
        self::say("Done: {$app}");

        return 0;
    }

    /** @param array<string, list<string>> $options */
    private static function macosDmg(array $options): int
    {
        $config   = Config::load(self::one($options, 'config'));
        $version  = self::one($options, 'app-version');
        $output   = isset($options['output']) ? self::one($options, 'output') : MacDmg::outputPath($config, $version);
        $codesign = Codesign::fromEnvironment();
        $work     = null;

        if (isset($options['app'])) {
            $app = self::one($options, 'app');

            if (!is_dir($app . '/Contents')) {
                throw new PackagerException("--app={$app} is not an app bundle.");
            }

            Codesign::verify($app);

            if (!$codesign->adHoc() && Codesign::isAdHoc($app)) {
                // Imaged into a Developer ID image, an ad-hoc app would fail only at notarisation.
                throw new PackagerException("--app={$app} is signed ad hoc, but " . Codesign::IDENTITY_ENV . ' is set: build it with the identity.');
            }
        } else {
            $work = Filesystem::tempDir('macos-app');
            $app  = $work . '/' . $config->macos()->name . '.app';
            self::buildApp($config, $options, $app);
        }

        try {
            MacDmg::build($config, $app, $output, $codesign, !isset($options['no-style']), self::log(...));
        } finally {
            if ($work !== null) {
                Filesystem::remove($work);
            }
        }

        self::say("Done: {$output} (" . Filesystem::size((int) filesize($output)) . ')');

        return 0;
    }

    /**
     * Fetches, assembles, signs and checks a bundle at $app.
     *
     * @param array<string, list<string>> $options
     */
    private static function buildApp(Config $config, array $options, string $app): void
    {
        $version = self::one($options, 'app-version');
        $arch    = isset($options['arch']) ? Arch::parse(self::one($options, 'arch')) : Arch::Aarch64;

        if ($arch !== Arch::Aarch64) {
            throw new PackagerException('macOS packages are arm64 (aarch64) only.');
        }

        $config->macos();
        $codesign = Codesign::fromEnvironment();
        $fetcher  = self::fetcher($options);
        $runtimes = [];

        foreach (['shell', 'sfx'] as $name) {
            $runtimes[$name] = $fetcher->fetch($config->runtime($name, Platform::Macos), $arch);
            self::say("{$name}: {$runtimes[$name]}");
        }

        $work = Filesystem::tempDir('macos');

        try {
            $payload = $work . '/' . $config->workerBinary . '.phar';
            (new PharBuilder(self::log(...)))->build($config, $payload, self::timestamp());

            $check = !isset($options['no-check']) && WorkerCheck::canRun($arch, Platform::Macos);

            if ($check) {
                WorkerCheck::iniProbe($runtimes['sfx'], $config->ini);
            }

            MacApp::assemble($config, $runtimes, $payload, $app, $version);
            self::say('Signing (' . $codesign->describe() . ', hardened runtime)');
            MacApp::sign($config, $app, $codesign);

            if ($check) {
                $header = WorkerCheck::handshakeAt("{$app}/Contents/MacOS/{$config->workerBinary}");
                self::say('Worker check: the ini block applies, and the signed worker in the bundle answered ' . $header);
            } else {
                self::say('Worker check skipped' . (isset($options['no-check']) ? '.' : ': it runs only on an arm64 Mac.'));
            }
        } finally {
            Filesystem::remove($work);
        }
    }

    /** @param array<string, list<string>> $options */
    private static function windowsTree(array $options): int
    {
        $config = Config::load(self::one($options, 'config'));
        $tree   = isset($options['output']) ? self::one($options, 'output') : WindowsTree::outputPath($config);
        self::buildTree($config, $options, $tree, Authenticode::fromEnvironment());
        self::say("Done: {$tree}");

        return 0;
    }

    /** @param array<string, list<string>> $options */
    private static function windowsInstaller(array $options): int
    {
        $config  = Config::load(self::one($options, 'config'));
        $version = self::one($options, 'app-version');
        $output  = isset($options['output']) ? self::one($options, 'output') : WindowsTree::installerPath($config, $version);
        $signer  = Authenticode::fromEnvironment();
        $work    = Filesystem::tempDir('windows-installer');

        try {
            $tree = $work . '/' . $config->windows()->name;
            self::buildTree($config, $options, $tree, $signer);
            Filesystem::mkdir(\dirname($output));
            WindowsInstaller::build($config, $tree, $output, $version, $signer->enabled() ? WindowsInstaller::signCommand() : null, self::log(...));

            if ($signer->enabled()) {
                self::say('Signed the installer: ' . $signer->sign($output, hasOverlay: true));
            }

            // Of the final (signed) bytes, as for the Linux tarball and the macOS image.
            Filesystem::write($output . '.sha256', hash_file('sha256', $output) . '  ' . basename($output) . "\n");
        } finally {
            Filesystem::remove($work);
        }

        self::say("Done: {$output} (" . Filesystem::size((int) filesize($output)) . ')');

        return 0;
    }

    /** @param array<string, list<string>> $options */
    private static function windowsSign(array $options): int
    {
        $file   = self::one($options, 'file');
        $signer = Authenticode::fromEnvironment();

        if (!$signer->enabled()) {
            throw new PackagerException("Cannot sign {$file}: " . $signer->describe() . '.');
        }

        self::say("Signed {$file}: " . $signer->sign($file, hasOverlay: isset($options['overlay'])));

        return 0;
    }

    /**
     * Fetches, assembles, writes the resources, signs and checks the Windows tree at $tree.
     *
     * @param array<string, list<string>> $options
     */
    private static function buildTree(Config $config, array $options, string $tree, Authenticode $signer): void
    {
        $version = self::one($options, 'app-version');
        $arch    = isset($options['arch']) ? Arch::parse(self::one($options, 'arch')) : Arch::X86_64;

        if ($arch !== Arch::X86_64) {
            throw new PackagerException('Windows packages are x86_64 only.');
        }

        $config->windows();
        $fetcher  = self::fetcher($options);
        $runtimes = [];

        foreach (['shell', 'sfx'] as $name) {
            $runtimes[$name] = $fetcher->fetch($config->runtime($name, Platform::Windows), $arch);
            self::say("{$name}: {$runtimes[$name]}");
        }

        $work = Filesystem::tempDir('windows');

        try {
            $payload = $work . '/' . $config->workerBinary . '.phar';
            (new PharBuilder(self::log(...)))->build($config, $payload, self::timestamp());

            $check = !isset($options['no-check']) && WorkerCheck::canRun($arch, Platform::Windows);

            if ($check) {
                WorkerCheck::iniProbe($runtimes['sfx'], $config->ini, Platform::Windows);
            }

            WindowsTree::assemble($config, $runtimes, $payload, $tree, $version);
            self::say('Icon and version resources written into ' . WindowsTree::shellExe($config) . ' and ' . WindowsTree::workerExe($config));

            if ($signer->enabled()) {
                self::say('Signing (' . $signer->describe() . ')');
                self::say('Signed by ' . WindowsTree::sign($config, $tree, $signer));
            } else {
                self::say('Not signed: ' . $signer->describe());
            }

            if ($check) {
                $header = WorkerCheck::handshakeAt("{$tree}/" . WindowsTree::workerExe($config));
                self::say('Worker check: the ini block applies, and the worker in the tree answered ' . $header);
            } else {
                self::say('Worker check skipped' . (isset($options['no-check']) ? '.' : ': it runs only on a Windows x86_64 host.'));
            }
        } finally {
            Filesystem::remove($work);
        }
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
        $arch     = Arch::parse(self::one($options, 'arch'));
        $platform = isset($options['platform'])
            ? (Platform::tryFrom(self::one($options, 'platform')) ?? throw new PackagerException('--platform is linux, macos or windows.'))
            : Platform::Linux;
        $fetcher  = self::fetcher($options);

        foreach ($config->runtimes[$platform->value] ?? [] as $runtime) {
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
        $flags   = ['no-check', 'no-style', 'overlay'];

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
