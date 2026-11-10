<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Windows;

use Grafida\ShellPackager\Arch;
use Grafida\ShellPackager\Config;
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;

/**
 * The Windows install tree (grafida/grafida#91): what an installer copies, and what runs. For an
 * app whose `windows.name` is `Grafida` and whose worker is `grafida-worker`:
 *
 * ```
 * Grafida/
 *   Grafida.exe               the shell, with the app's icon and version resource
 *   grafida-worker.exe        the phpmicro sibling-phar SFX stub, likewise
 *   grafida-worker.phar       the worker payload (extra-INI block + PHAR)
 *   shell.json                the app's manifest
 *   Grafida.ico               windows.icon (for shortcuts and Add/Remove Programs)
 *   …                         windows.files
 * ```
 *
 * The shell finds its manifest and its worker beside itself; the stub finds `<its name>.phar`
 * beside itself. The stub and its `.phar` are never recombined: Authenticode would sign past the
 * PHAR and corrupt it.
 */
final class WindowsTree
{
    /** `{name}` in `windows.tree`, relative to the app root. */
    public static function outputPath(Config $config): string
    {
        $path = strtr($config->windows()->treeOutput, ['{name}' => $config->windows()->name]);

        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 ? $path : $config->root . '/' . $path;
    }

    /** `{name}`, `{version}`, `{arch}`, `{debarch}` in `windows.output`. */
    public static function installerPath(Config $config, string $version): string
    {
        $path = strtr($config->windows()->installerOutput, [
            '{name}'    => $config->windows()->name,
            '{version}' => $version,
            '{arch}'    => Arch::X86_64->value,
            '{debarch}' => Arch::X86_64->debian(),
        ]);

        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 ? $path : $config->root . '/' . $path;
    }

    public static function shellExe(Config $config): string
    {
        return $config->windows()->name . '.exe';
    }

    public static function workerExe(Config $config): string
    {
        return $config->workerBinary . '.exe';
    }

    public static function iconFile(Config $config): ?string
    {
        return $config->windows()->icon === null ? null : $config->windows()->name . '.ico';
    }

    /**
     * Writes the tree at $tree (replacing any), with the resources written; unsigned.
     *
     * @param array{shell: string, sfx: string} $runtimes paths of verified binaries
     */
    public static function assemble(Config $config, array $runtimes, string $payload, string $tree, string $version): void
    {
        $windows = $config->windows();
        $shell   = self::shellExe($config);
        $worker  = self::workerExe($config);

        if (strcasecmp($shell, $worker) === 0) {
            throw new PackagerException("windows.name '{$windows->name}' and the worker '{$config->workerBinary}' would be the same file.");
        }

        foreach (['shell' => $runtimes['shell'], 'sfx' => $runtimes['sfx']] as $what => $path) {
            self::checkBinary($what, PeFile::read($path));
        }

        if (is_dir($tree)) {
            Filesystem::remove($tree);
        }

        Filesystem::mkdir($tree);
        self::copy($runtimes['shell'], "{$tree}/{$shell}");
        self::copy($runtimes['sfx'], "{$tree}/{$worker}");
        self::copy($payload, "{$tree}/{$config->workerBinary}.phar");
        self::copy($config->manifestPath, "{$tree}/shell.json");

        $icon = $windows->icon === null ? null : IconFile::read($windows->icon);

        if ($windows->icon !== null) {
            self::copy($windows->icon, "{$tree}/" . self::iconFile($config));
        }

        foreach ($windows->files as $name => $source) {
            Filesystem::mkdir(\dirname("{$tree}/{$name}"));
            self::copy($source, "{$tree}/{$name}");
        }

        foreach ([$shell => $windows->shellDescription, $worker => $windows->workerDescription] as $exe => $description) {
            ResourceWriter::apply("{$tree}/{$exe}", $icon, self::version($config, $version, $exe, $description));
        }
    }

    /** Signs both executables and checks each signature. Returns who signed them. */
    public static function sign(Config $config, string $tree, Authenticode $authenticode): string
    {
        $subject = '';

        foreach ([self::workerExe($config), self::shellExe($config)] as $exe) {
            $subject = $authenticode->sign("{$tree}/{$exe}");
        }

        return $subject;
    }

    public static function version(Config $config, string $appVersion, string $exe, string $description): VersionInfo
    {
        $windows = $config->windows();
        $numeric = VersionInfo::numeric($appVersion);

        return new VersionInfo($numeric, $numeric, [
            'CompanyName'      => $windows->companyName,
            'FileDescription'  => $description,
            'FileVersion'      => $appVersion,
            'InternalName'     => pathinfo($exe, \PATHINFO_FILENAME),
            'LegalCopyright'   => $windows->legalCopyright,
            'OriginalFilename' => $exe,
            'ProductName'      => $windows->productName,
            'ProductVersion'   => $appVersion,
        ]);
    }

    /** The architecture, the overlay and the signature of a binary going into the tree. */
    private static function checkBinary(string $what, PeFile $binary): void
    {
        if (!$binary->isAmd64()) {
            throw new PackagerException("The {$what} ({$binary->path}) is not an x86_64 executable; Windows packages are x86_64 only.");
        }

        if ($binary->subsystem !== PeFile::SUBSYSTEM_GUI) {
            throw new PackagerException("The {$what} ({$binary->path}) is a console program: it would open a console window. Use the GUI build.");
        }

        if ($binary->overlayBytes() > 0) {
            throw new PackagerException(
                "The {$what} ({$binary->path}) carries {$binary->overlayBytes()} bytes past its image: an SFX with its "
                . 'payload still appended. Signing it would corrupt the PHAR; package the bare stub.'
            );
        }

        if ($binary->isSigned()) {
            throw new PackagerException("The {$what} ({$binary->path}) is signed already; the packager writes its resources, then signs it.");
        }
    }

    private static function copy(string $from, string $to): void
    {
        if (!copy($from, $to)) {
            throw new PackagerException("Cannot copy {$from} to {$to}.");
        }

        chmod($to, 0o644);
    }
}
