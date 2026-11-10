<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Windows;

use Grafida\ShellPackager\Config;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Process;

/**
 * The installer (grafida/grafida#91): `makensis` (it runs on macOS and Linux too) on the **app's
 * own** NSIS script, which decides everything an installer decides (per-user or not, the install
 * folder, shortcuts, what the uninstaller removes, how a missing WebView2 runtime is handled). The
 * packager hands it the tree and these defines, all absolute paths and plain strings:
 *
 * | define            | value |
 * |---|---|
 * | `SRCDIR`          | the tree ({@see WindowsTree}) |
 * | `OUTFILE`         | the installer to write |
 * | `APPNAME`         | `windows.name` |
 * | `APPID`           | the manifest's `id` |
 * | `APPEXE`          | `<name>.exe`, the shell |
 * | `WORKEREXE`       | `<worker>.exe` |
 * | `WORKERPHAR`      | `<worker>.phar` |
 * | `ICONFILE`        | the `.ico` (only when `windows.icon` is set) |
 * | `APPVERSION`      | the app's version, as given |
 * | `VIVERSION`       | the four-part version (`VIProductVersion` needs one) |
 * | `PUBLISHER`       | `windows.version.companyName` |
 * | `SHELL_PACKAGER_SIGN` | only when signing: a command signing `%1`, for `!uninstfinalize` |
 *
 * plus `windows.installer.defines`. With signing configured the script **must** sign its
 * uninstaller, which makensis writes inside the installer and nothing can reach afterwards:
 *
 * ```nsis
 * !ifdef SHELL_PACKAGER_SIGN
 *   !uninstfinalize '${SHELL_PACKAGER_SIGN}' = 0
 * !endif
 * ```
 *
 * The packager checks that it did: `windows-sign` records each file it signs in the file named by
 * `SHELL_PACKAGER_SIGN_LOG`, which the packager points at a fresh file for the makensis run, and an
 * empty record fails the build. Then it signs the installer itself.
 */
final class WindowsInstaller
{
    /** The defines the packager passes; a configuration may not set these. */
    public const DEFINES = ['SRCDIR', 'OUTFILE', 'APPNAME', 'APPID', 'APPEXE', 'WORKEREXE', 'WORKERPHAR', 'ICONFILE', 'APPVERSION', 'VIVERSION', 'PUBLISHER', 'SHELL_PACKAGER_SIGN'];

    /**
     * Runs makensis; returns the installer's path.
     *
     * @param ?string $signCommand the `%1` signing command for the uninstaller, when signing
     */
    public static function build(Config $config, string $tree, string $output, string $version, ?string $signCommand, callable $log): string
    {
        $windows = $config->windows();
        $script  = $windows->installerScript ?? throw new PackagerException('windows.installer.script names no NSIS script.');

        if (!Process::exists('makensis')) {
            throw new PackagerException('The installer needs makensis (NSIS 3.08 or later) on PATH: brew install makensis, or nsis.sourceforge.io.');
        }

        $nsis = trim(Process::mustRun(['makensis', '-VERSION']));

        if (preg_match('/^v?(\d+)\.(\d+)/', $nsis, $m) === 1 && ((int) $m[1] < 3 || ((int) $m[1] === 3 && (int) $m[2] < 8))) {
            throw new PackagerException("makensis {$nsis} is too old: !uninstfinalize needs NSIS 3.08 or later.");
        }

        if ($signCommand !== null && !str_contains((string) file_get_contents($script), 'SHELL_PACKAGER_SIGN')) {
            throw new PackagerException(
                "{$script} does not use SHELL_PACKAGER_SIGN, so its uninstaller would ship unsigned. Add: "
                . "!ifdef SHELL_PACKAGER_SIGN / !uninstfinalize '\${SHELL_PACKAGER_SIGN}' = 0 / !endif"
            );
        }

        $defines = [
            'SRCDIR'     => self::native($tree),
            'OUTFILE'    => self::native($output),
            'APPNAME'    => $windows->name,
            'APPID'      => $config->appId,
            'APPEXE'     => WindowsTree::shellExe($config),
            'WORKEREXE'  => WindowsTree::workerExe($config),
            'WORKERPHAR' => $config->workerBinary . '.phar',
            'APPVERSION' => $version,
            'VIVERSION'  => VersionInfo::dotted(VersionInfo::numeric($version)),
            'PUBLISHER'  => $windows->companyName,
        ];

        if (WindowsTree::iconFile($config) !== null) {
            $defines['ICONFILE'] = self::native($tree . '/' . WindowsTree::iconFile($config));
        }

        if ($signCommand !== null) {
            $defines['SHELL_PACKAGER_SIGN'] = $signCommand;
        }

        $command = ['makensis', '-V2', '-INPUTCHARSET', 'UTF8'];

        foreach ($defines + $windows->defines as $name => $value) {
            $command[] = "-D{$name}={$value}";
        }

        $command[] = $script;
        @unlink($output);
        $log('makensis ' . basename($script) . " ({$nsis})");
        $record = null;

        if ($signCommand !== null) {
            $record = tempnam(sys_get_temp_dir(), 'shell-packager-signed-');
            putenv(Authenticode::LOG_ENV . '=' . $record);
        }

        try {
            Process::mustRun($command);

            if ($record !== null && trim((string) file_get_contents($record)) === '') {
                throw new PackagerException(
                    basename($script) . " was given SHELL_PACKAGER_SIGN but signed nothing: its uninstaller ships unsigned. Does it run !uninstfinalize '\${SHELL_PACKAGER_SIGN}' = 0?"
                );
            }
        } finally {
            if ($record !== null) {
                putenv(Authenticode::LOG_ENV);
                @unlink($record);
            }
        }

        if (!is_file($output)) {
            throw new PackagerException("makensis did not write {$output}: does the script use OutFile \"\${OUTFILE}\"?");
        }

        return $output;
    }

    /** The command `!uninstfinalize` runs to sign the uninstaller: this packager, on `%1`. */
    public static function signCommand(): string
    {
        $self = realpath(__DIR__ . '/../../bin/shell-packager');

        if ($self === false) {
            throw new PackagerException('Cannot find bin/shell-packager to sign the uninstaller with.');
        }

        return '"' . \PHP_BINARY . '" "' . $self . '" windows-sign --overlay --file="%1"';
    }

    /** A path as makensis on this host wants it (on Windows, backslashes). */
    private static function native(string $path): string
    {
        return \PHP_OS_FAMILY === 'Windows' ? str_replace('/', '\\', $path) : $path;
    }
}
