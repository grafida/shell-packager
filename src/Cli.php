<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager;

use Grafida\ShellPackager\Linux\LinuxPackage;
use Grafida\ShellPackager\Linux\AppImage;
use Grafida\ShellPackager\Linux\AppImageConfig;
use Grafida\ShellPackager\Linux\LinuxTarball;
use Grafida\ShellPackager\Linux\Nfpm;
use Grafida\ShellPackager\Linux\Pkgbuild;
use Grafida\ShellPackager\Linux\Squashfs;
use Grafida\ShellPackager\Macos\Codesign;
use Grafida\ShellPackager\Macos\MacApp;
use Grafida\ShellPackager\Macos\MacDmg;
use Grafida\ShellPackager\Macos\MachO;
use Grafida\ShellPackager\Phar\PharBuilder;
use Grafida\ShellPackager\Phar\WorkerCheck;
use Grafida\ShellPackager\Shell\ReleaseSource;
use Grafida\ShellPackager\Shell\ReleaseSources;
use Grafida\ShellPackager\Shell\ShellAssets;
use Grafida\ShellPackager\Shell\ShellFiles;
use Grafida\ShellPackager\Shell\ShellLock;
use Grafida\ShellPackager\Windows\Authenticode;
use Grafida\ShellPackager\Windows\WindowsInstaller;
use Grafida\ShellPackager\Windows\WindowsTree;

/** `bin/shell-packager`: argument parsing and the commands. */
final class Cli
{
    public const VERSION = '0.6.0';

    public const USAGE = <<<'TXT'
        shell-packager 0.6.0: packaging for apps on the Grafida desktop shell
        Documentation: https://github.com/grafida/shell/blob/main/docs/user/packaging.md

        Usage:
          shell-packager linux-tarball --config=FILE --arch=ARCH --app-version=VERSION [options]
          shell-packager linux-deb --config=FILE --arch=ARCH --app-version=VERSION [options]
          shell-packager linux-rpm --config=FILE --arch=ARCH --app-version=VERSION [options]
          shell-packager linux-appimage --config=FILE --arch=ARCH --app-version=VERSION [options]
          shell-packager linux-pkgbuild --config=FILE --app-version=VERSION [--arch=ARCH]… [--output=FILE]
          shell-packager macos-app --config=FILE --app-version=VERSION [--arch=ARCH] [options]
          shell-packager macos-dmg --config=FILE --app-version=VERSION [--arch=ARCH] [--app=APP] [--no-style] [options]
          shell-packager windows-tree --config=FILE --app-version=VERSION [--output=DIR] [options]
          shell-packager windows-installer --config=FILE --app-version=VERSION [--output=FILE] [options]
          shell-packager windows-sign --file=FILE [--overlay]
          shell-packager phar --config=FILE --output=FILE [--no-check]
          shell-packager fetch --config=FILE --arch=ARCH [--platform=linux|macos|windows] [--nfpm] [--local=NAME=PATH]…
          shell-packager update --config=FILE
          shell-packager version | help

        Options:
          --config=FILE        the app's packaging configuration (shell-package.json)
          --arch=ARCH          x86_64 or aarch64 (amd64/arm64 accepted); macOS defaults to aarch64,
                               Windows is x86_64 only
          --app-version=V      the app's version (macOS: one to three dot-separated integers)
          --output=FILE        where to write (overrides linux.output, linux.package.output, linux.appimage.output,
                               linux.pkgbuild.output, macos.app, macos.output,
                               windows.tree or windows.output)
          --app=APP            macos-dmg: image this signed bundle instead of building one
          --no-sign            macos-app, macos-dmg, windows-tree, windows-installer: sign nothing, whatever the
                               environment says. macOS keeps the binaries' own ad-hoc linker signature and does
                               not seal the bundle. See the caveats in the documentation.
          --no-style           macos-dmg: no Finder layout (it needs Finder and its Automation
                               permission; a CI runner has neither)
          --local=NAME=PATH    use a local file for runtime NAME (shell, launcher, sfx, appimage-runtime; nfpm for linux-deb/rpm). UNPINNED:
                               for testing an unreleased build only; announced on every use. Wins over shell.local
          --no-check           skip running the packaged worker (it runs only on a host of the
                               target's OS and architecture anyway; a macOS x86_64 worker also
                               on an arm64 Mac with Rosetta 2)
          --nfpm               fetch: also fetch and verify the pinned nfpm for this build host
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

        linux-deb and linux-rpm write the package with a pinned nfpm (macOS or Linux build host), from the
        same file list as the tarball, under /opt/<linux.name>; they need linux.package (maintainer, license).

        linux-appimage needs mksquashfs: the host's (squashfs-tools), or one in a Docker container the
        packager builds once from its own pinned Dockerfile (linux.appimage.squashfs: auto, host, docker).

        The shell and launcher are pinned by shell.version (a Composer version constraint) against the
        releases of shell.repository (default grafida/shell; public repositories only). update resolves it,
        downloads and checks every shell file, and writes the lock beside the configuration
        (shell-package.lock): commit it. A build reads the lock and makes no GitHub API call; with no lock
        it writes one first, and a lock that no longer matches shell.version fails the build. Releases are
        listed through a logged-in gh when there is one, otherwise the GitHub API (GH_TOKEN or GITHUB_TOKEN
        if set). shell.local names local files that stand in for any shell file, announced as UNPINNED.

        Timestamps come from SOURCE_DATE_EPOCH when it is set (Linux output is byte-reproducible;
        macOS output is not). Downloads are cached in SHELL_PACKAGER_CACHE (default:
        $XDG_CACHE_HOME/grafida-shell-packager): pinned runtimes by SHA-256, the shell by repository and version.
        TXT;

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        try {
            $options = self::options(\array_slice($argv, 2));

            return match ($command) {
                'linux-tarball' => self::linuxTarball($options),
                'linux-deb'     => self::linuxPackage($options, 'deb'),
                'linux-rpm'     => self::linuxPackage($options, 'rpm'),
                'linux-appimage' => self::linuxAppImage($options),
                'linux-pkgbuild' => self::linuxPkgbuild($options),
                'macos-app'     => self::macosApp($options),
                'macos-dmg'     => self::macosDmg($options),
                'windows-tree'  => self::windowsTree($options),
                'windows-installer' => self::windowsInstaller($options),
                'windows-sign'  => self::windowsSign($options),
                'phar'          => self::phar($options),
                'fetch'         => self::fetch($options),
                'update'        => self::update($options),
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

        $runtimes = self::linuxRuntimes($config, $fetcher, $arch);

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
    private static function linuxPackage(array $options, string $format): int
    {
        $config  = Config::load(self::one($options, 'config'));
        $config->linuxPackage();
        $arch    = Arch::parse(self::one($options, 'arch'));
        $version = self::one($options, 'app-version');
        LinuxPackage::packageVersion($version);
        $mtime   = self::timestamp();
        $output  = isset($options['output']) ? self::one($options, 'output') : LinuxPackage::outputPath($config, $format, $arch, $version);
        $fetcher = self::fetcher($options);

        $runtimes = self::linuxRuntimes($config, $fetcher, $arch);

        $work = Filesystem::tempDir('linux-' . $format);

        try {
            $nfpm = Nfpm::binary($fetcher, $work);
            self::say("nfpm: {$nfpm}");
            $payload = $work . '/' . $config->workerBinary . '.phar';
            (new PharBuilder(self::log(...)))->build($config, $payload, $mtime);

            if (!isset($options['no-check'])) {
                self::check($config, $arch, $runtimes['sfx'], $payload);
            }

            LinuxPackage::write($config, $format, $runtimes, $payload, $output, $mtime, $arch, $version, $nfpm, $work);
        } finally {
            Filesystem::remove($work);
        }

        self::say("Done: {$output} (" . Filesystem::size((int) filesize($output)) . ')');

        return 0;
    }

    /** @param array<string, list<string>> $options */
    private static function linuxAppImage(array $options): int
    {
        $config  = Config::load(self::one($options, 'config'));
        $arch    = Arch::parse(self::one($options, 'arch'));
        // Before the downloads and the PHAR: a missing mksquashfs (or Docker) should cost nothing.
        $squashfs = Squashfs::resolve($config->appImage ?? new AppImageConfig(), self::log(...));
        $version = self::one($options, 'app-version');
        $mtime   = self::timestamp();
        $output  = isset($options['output']) ? self::one($options, 'output') : AppImage::outputPath($config, $arch, $version);
        $fetcher = self::fetcher($options);

        $runtimes = self::linuxRuntimes($config, $fetcher, $arch);

        $runtime = $fetcher->fetch($config->runtime('appimage-runtime'), $arch);
        self::say("appimage-runtime: {$runtime}");

        $work = Filesystem::tempDir('appimage-payload');

        try {
            $payload = $work . '/' . $config->workerBinary . '.phar';
            (new PharBuilder(self::log(...)))->build($config, $payload, $mtime);

            if (!isset($options['no-check'])) {
                self::check($config, $arch, $runtimes['sfx'], $payload);
            }

            AppImage::write($config, $runtimes, $runtime, $payload, $output, $mtime, $arch, $squashfs);
        } finally {
            Filesystem::remove($work);
        }

        self::say("Done: {$output} (" . Filesystem::size((int) filesize($output)) . ')');

        return 0;
    }

    /** @param array<string, list<string>> $options */
    private static function linuxPkgbuild(array $options): int
    {
        $config  = Config::load(self::one($options, 'config'));
        $version = self::one($options, 'app-version');
        $archs   = isset($options['arch'])
            ? array_map(static fn (string $a): Arch => Arch::parse($a), $options['arch'])
            : [Arch::X86_64, Arch::Aarch64];
        $tarballs = [];

        foreach ($archs as $arch) {
            $path = LinuxTarball::outputPath($config, $arch, $version);

            // Without --arch, whichever tarballs have been built; with it, each one must exist.
            if (isset($options['arch']) || is_file($path)) {
                $tarballs[$arch->value] = $path;
            }
        }

        if ($tarballs === []) {
            throw new PackagerException('No tarball has been built for ' . $version . ': run linux-tarball first.');
        }

        $output = isset($options['output']) ? self::one($options, 'output') : Pkgbuild::outputPath($config);
        Filesystem::write($output, Pkgbuild::render($config, $version, $tarballs));
        self::say("Done: {$output} (" . implode(', ', array_keys($tarballs)) . ')');

        return 0;
    }

    /** @param array<string, list<string>> $options */
    private static function codesign(array $options): Codesign
    {
        return isset($options['no-sign']) ? Codesign::unsigned() : Codesign::fromEnvironment();
    }

    /** @param array<string, list<string>> $options */
    private static function authenticode(array $options): Authenticode
    {
        return isset($options['no-sign']) ? new Authenticode('none') : Authenticode::fromEnvironment();
    }

    /** @param array<string, list<string>> $options */
    private static function macosArch(array $options): Arch
    {
        return isset($options['arch']) ? Arch::parse(self::one($options, 'arch')) : Arch::Aarch64;
    }

    /** @param array<string, list<string>> $options */
    private static function macosApp(array $options): int
    {
        $config = Config::load(self::one($options, 'config'));
        $app    = isset($options['output']) ? self::one($options, 'output') : MacApp::outputPath($config, self::macosArch($options));
        self::buildApp($config, $options, $app);
        self::say("Done: {$app}");

        return 0;
    }

    /** @param array<string, list<string>> $options */
    private static function macosDmg(array $options): int
    {
        $config   = Config::load(self::one($options, 'config'));
        $version  = self::one($options, 'app-version');
        $output   = isset($options['output']) ? self::one($options, 'output') : MacDmg::outputPath($config, $version, self::macosArch($options));
        $codesign = self::codesign($options);
        $work     = null;

        if (isset($options['app'])) {
            $app = self::one($options, 'app');

            if (!is_dir($app . '/Contents')) {
                throw new PackagerException("--app={$app} is not an app bundle.");
            }

            if (!$codesign->skip) {
                Codesign::verify($app);
            }

            $arch = self::macosArch($options);

            // The image is named after --arch: an arm64 bundle must not become an amd64 image.
            $binaries = glob($app . '/Contents/MacOS/*');

            foreach (\is_array($binaries) ? $binaries : [] as $binary) {
                $have = MachO::read($binary)->arch();

                if ($have !== $arch) {
                    throw new PackagerException("--app={$app}: {$binary} is " . ($have?->debian() ?? 'of an unknown CPU') . ", not {$arch->debian()} (--arch).");
                }
            }

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
        $arch    = self::macosArch($options);

        $config->macos();
        $codesign = self::codesign($options);
        $fetcher  = self::fetcher($options);
        $runtimes = self::shellAndSfx($config, $fetcher, Platform::Macos, $arch);

        $work = Filesystem::tempDir('macos');

        try {
            $payload = $work . '/' . $config->workerBinary . '.phar';
            (new PharBuilder(self::log(...)))->build($config, $payload, self::timestamp());

            $check = !isset($options['no-check']) && WorkerCheck::canRun($arch, Platform::Macos);

            if ($check) {
                WorkerCheck::iniProbe($runtimes['sfx'], $config->ini);
            }

            MacApp::assemble($config, $runtimes, $payload, $app, $version, $arch);
            if ($codesign->skip) {
                self::say('NOT signing (--no-sign): the binaries keep the ad-hoc linker signature they came with, and the bundle is not sealed.');
            } else {
                self::say('Signing (' . $codesign->describe() . ', hardened runtime)');
            }

            MacApp::sign($config, $app, $codesign);

            if ($check) {
                $header = WorkerCheck::handshakeAt("{$app}/Contents/MacOS/{$config->workerBinary}");
                self::say('Worker check' . WorkerCheck::translation($arch, Platform::Macos) . ': the ini block applies, and the worker in the bundle answered ' . $header);
            } else {
                self::say('Worker check skipped' . (isset($options['no-check'])
                    ? '.'
                    : ": this machine cannot run a macOS {$arch->value} binary (an Intel Mac, or for x86_64 an arm64 Mac with Rosetta 2, can)."));
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
        self::buildTree($config, $options, $tree, self::authenticode($options));
        self::say("Done: {$tree}");

        return 0;
    }

    /** @param array<string, list<string>> $options */
    private static function windowsInstaller(array $options): int
    {
        $config  = Config::load(self::one($options, 'config'));
        $version = self::one($options, 'app-version');
        $output  = isset($options['output']) ? self::one($options, 'output') : WindowsTree::installerPath($config, $version);
        $signer  = self::authenticode($options);
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
        $runtimes = self::shellAndSfx($config, $fetcher, Platform::Windows, $arch);

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

        if ($config->shell !== null) {
            $shell = self::shellFiles($config, $fetcher);

            foreach (array_keys(ShellAssets::SLOTS[$platform->value]) as $component) {
                self::say("{$component}: " . $shell->path($platform, $component, $arch));
            }
        }

        foreach ($config->runtimes[$platform->value] ?? [] as $runtime) {
            self::say("{$runtime->name}: " . $fetcher->fetch($runtime, $arch));
        }

        if (isset($options['nfpm'])) {
            // A tool of this build host (any --arch is ignored for it), pinned in the packager.
            self::say('nfpm: ' . $fetcher->fetch(Nfpm::runtime(\PHP_OS_FAMILY), Arch::host() ?? $arch));
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
    private static function update(array $options): int
    {
        $config = Config::load(self::one($options, 'config'));
        self::shellFiles($config, self::fetcher($options))->update();
        self::say('Wrote ' . ShellLock::pathFor($config->path) . '.');

        return 0;
    }

    private static function shellFiles(Config $config, Fetcher $fetcher): ShellFiles
    {
        return new ShellFiles($config, $fetcher, static fn (): ReleaseSource => ReleaseSources::detect(), static function (string $line): void {
            self::say($line);
        });
    }

    /**
     * The files of a Linux package: the shell and launcher from the shell block, the SFX from runtimes.
     *
     * @return array{launcher: string, shell: string, sfx: string}
     */
    private static function linuxRuntimes(Config $config, Fetcher $fetcher, Arch $arch): array
    {
        $shell = self::shellFiles($config, $fetcher);

        return [
            'launcher' => self::said('launcher', $shell->path(Platform::Linux, 'launcher', $arch)),
            'shell'    => self::said('shell', $shell->path(Platform::Linux, 'shell', $arch)),
            'sfx'      => self::said('sfx', $fetcher->fetch($config->runtime('sfx'), $arch)),
        ];
    }

    /**
     * The files of a macOS or Windows package: the shell from the shell block, the SFX from runtimes.
     *
     * @return array{shell: string, sfx: string}
     */
    private static function shellAndSfx(Config $config, Fetcher $fetcher, Platform $platform, Arch $arch): array
    {
        return [
            'shell' => self::said('shell', self::shellFiles($config, $fetcher)->path($platform, 'shell', $arch)),
            'sfx'   => self::said('sfx', $fetcher->fetch($config->runtime('sfx', $platform), $arch)),
        ];
    }

    /** Prints "name: path" and returns the path. */
    private static function said(string $name, string $path): string
    {
        self::say("{$name}: {$path}");

        return $path;
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
        $flags   = ['no-check', 'no-sign', 'no-style', 'overlay', 'nfpm'];

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
