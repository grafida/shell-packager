<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Macos;

use Grafida\ShellPackager\Arch;
use Grafida\ShellPackager\Config;
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Process;

/**
 * The macOS app bundle (grafida/grafida#88), one per architecture: arm64, or x86_64 for Intel Macs
 * (grafida/grafida#92). Every binary in it must be a thin Mach-O of that architecture. For an app whose `macos.name` is `Grafida` and whose
 * worker is `grafida-worker`:
 *
 * ```
 * Grafida.app/Contents/
 *   Info.plist                 generated ({@see InfoPlist})
 *   PkgInfo
 *   MacOS/Grafida              the shell (CFBundleExecutable)
 *   MacOS/grafida-worker       the phpmicro sibling-phar SFX stub
 *   Resources/grafida-worker.phar   the worker payload (extra-INI block + PHAR)
 *   Resources/shell.json       the app's manifest
 *   Resources/Grafida.icns     macos.icon
 *   Resources/…                macos.resources
 * ```
 *
 * The shell finds its manifest in `../Resources` and its worker beside itself; the stub finds its
 * payload at `../Resources/<its name>.phar`. Only code lives in `MacOS`: codesign refuses data
 * there. The stub and its `.phar` are never recombined (a signature would seal past the PHAR).
 */
final class MacApp
{
    /** `{name}`, `{arch}` (aarch64/x86_64) and `{debarch}` (arm64/amd64) in `macos.app`, relative to the app root. */
    public static function outputPath(Config $config, Arch $arch = Arch::Aarch64): string
    {
        $path = strtr($config->macos()->appOutput, [
            '{name}'    => $config->macos()->name,
            '{arch}'    => $arch->value,
            '{debarch}' => $arch->debian(),
        ]);

        if (!str_ends_with($path, '.app')) {
            throw new PackagerException("macos.app must name a .app bundle; got '{$path}'.");
        }

        return str_starts_with($path, '/') ? $path : $config->root . '/' . $path;
    }

    /**
     * Writes the bundle at $app (replacing any), unsigned.
     *
     * @param array{shell: string, sfx: string} $runtimes paths of verified binaries
     */
    public static function assemble(Config $config, array $runtimes, string $payload, string $app, string $version, Arch $arch = Arch::Aarch64): void
    {
        $macos = $config->macos();

        if (strcasecmp($macos->name, $config->workerBinary) === 0) {
            // APFS and HFS+ are case-insensitive by default: these would be one file.
            throw new PackagerException("macos.name '{$macos->name}' and the worker '{$config->workerBinary}' would be the same file in Contents/MacOS.");
        }

        foreach (['shell' => $runtimes['shell'], 'sfx' => $runtimes['sfx']] as $what => $path) {
            self::checkBinary($what, MachO::read($path), $arch);
        }

        if (is_dir($app)) {
            Filesystem::remove($app);
        }

        $contents = $app . '/Contents';
        Filesystem::mkdir($contents . '/MacOS');
        Filesystem::mkdir($contents . '/Resources');

        self::copy($runtimes['shell'], "{$contents}/MacOS/{$macos->name}", 0o755);
        self::copy($runtimes['sfx'], "{$contents}/MacOS/{$config->workerBinary}", 0o755);
        self::copy($payload, "{$contents}/Resources/{$config->workerBinary}.phar", 0o644);
        self::copy($config->manifestPath, "{$contents}/Resources/shell.json", 0o644);

        if ($macos->icon !== null) {
            self::copy($macos->icon, "{$contents}/Resources/{$macos->name}.icns", 0o644);
        }

        foreach ($macos->resources as $name => $source) {
            Filesystem::mkdir(\dirname("{$contents}/Resources/{$name}"));
            self::copy($source, "{$contents}/Resources/{$name}", 0o644);
        }

        $plist = InfoPlist::build($config, $version, $macos->icon !== null);
        Filesystem::write("{$contents}/Info.plist", InfoPlist::xml($plist));
        Filesystem::write("{$contents}/PkgInfo", 'APPL????');

        if (Process::exists('plutil')) {
            Process::mustRun(['plutil', '-lint', "{$contents}/Info.plist"]);
        }
    }

    /**
     * Signs inside-out: the worker stub, the shell, then the bundle (whose signature covers the main
     * executable again, so it carries the shell's entitlements too). Then every signature is
     * verified and read back: the hardened runtime on each binary, and exactly the entitlements the
     * configuration asked for.
     */
    public static function sign(Config $config, string $app, Codesign $codesign): void
    {
        if ($codesign->skip) {
            return;
        }

        $macos  = $config->macos();
        $worker = "{$app}/Contents/MacOS/{$config->workerBinary}";
        $shell  = "{$app}/Contents/MacOS/{$macos->name}";

        $codesign->sign($worker, $macos->entitlements['worker']);
        $codesign->sign($shell, $macos->entitlements['shell']);
        $codesign->sign($app, $macos->entitlements['shell']);

        foreach ([$worker, $shell, $app] as $path) {
            Codesign::verify($path);
        }

        foreach (['worker' => $worker, 'shell' => $shell] as $what => $path) {
            if (!Codesign::hasHardenedRuntime($path)) {
                throw new PackagerException("The {$what} was signed without the hardened runtime.");
            }

            $want = Codesign::entitlementsFile($macos->entitlements[$what]);
            $have = Codesign::entitlements($path);
            ksort($want);
            ksort($have);

            if ($want !== $have) {
                throw new PackagerException(
                    "The {$what}'s signature carries entitlements " . json_encode($have) . ', not the configured ' . json_encode($want) . '.'
                );
            }
        }
    }

    /** The minimum macOS, the architecture and the payload of a binary going into the bundle. */
    private static function checkBinary(string $what, MachO $binary, Arch $arch): void
    {
        if ($binary->arch() !== $arch) {
            $have = $binary->arch()?->debian() ?? \sprintf('CPU type 0x%08x', $binary->cpuType);

            throw new PackagerException("The {$what} ({$binary->path}) is an {$have} binary, not {$arch->debian()}: a bundle holds one architecture.");
        }

        if ($binary->appendedBytes() > 0) {
            throw new PackagerException(
                "The {$what} ({$binary->path}) carries {$binary->appendedBytes()} bytes past its Mach-O image: an SFX with its "
                . 'payload still appended. Signing it would seal past the PHAR and corrupt it; package the bare stub.'
            );
        }

        if ($binary->minos === null) {
            throw new PackagerException("The {$what} ({$binary->path}) names no minimum macOS (LC_BUILD_VERSION).");
        }

        if (!MachO::versionAtMost($binary->minos, InfoPlist::MINIMUM_SYSTEM_VERSION)) {
            throw new PackagerException(
                "The {$what} ({$binary->path}) needs macOS {$binary->minos}, above the bundle's LSMinimumSystemVersion "
                . InfoPlist::MINIMUM_SYSTEM_VERSION . ': it would be offered to Macs it cannot run on.'
            );
        }
    }

    private static function copy(string $from, string $to, int $mode): void
    {
        if (!copy($from, $to)) {
            throw new PackagerException("Cannot copy {$from} to {$to}.");
        }

        chmod($to, $mode);
    }
}
