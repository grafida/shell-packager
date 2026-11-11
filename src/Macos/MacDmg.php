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
 * The disk image (grafida/grafida#88): the signed bundle beside an `/Applications` link, laid out by
 * Finder, compressed (UDZO), then signed and notarised when the environment says so.
 *
 * - **The Finder layout is best-effort.** It drives Finder through AppleScript, which needs the
 *   Automation permission; a prompt nobody answers would block forever, so `osascript` runs under a
 *   time limit. It only styles a volume mounted at exactly `/Volumes/<volume name>`: with another
 *   volume of that name mounted, Finder's `disk "<name>"` would style the other one. Any failure
 *   leaves a working plain image and a warning.
 * - **The volume icon is written after the styling**, because Finder deletes a `.VolumeIcon.icns`
 *   that is there when it opens the window.
 * - **Notarisation** runs only with `MACOS_NOTARY_PROFILE` (a `notarytool` keychain profile) and a
 *   Developer ID (`MACOS_SIGN_IDENTITY`): submit and wait, staple, validate, then `spctl` on the
 *   image. It is never run on an ad-hoc build, which Gatekeeper always rejects.
 *
 * Unlike the Linux tarball, the image is not byte-reproducible: `hdiutil` and `codesign` are not.
 */
final class MacDmg
{
    public const NOTARY_ENV = 'MACOS_NOTARY_PROFILE';

    /** How long Finder may take to lay the window out before the styling is abandoned. */
    private const STYLE_TIMEOUT = 90;

    /** `{name}`, `{version}`, `{arch}` (aarch64/x86_64), `{debarch}` (arm64/amd64), relative to the app root. */
    public static function outputPath(Config $config, string $version, Arch $arch = Arch::Aarch64): string
    {
        $path = strtr($config->macos()->dmgOutput, [
            '{name}'    => $config->macos()->name,
            '{version}' => $version,
            '{arch}'    => $arch->value,
            '{debarch}' => $arch->debian(),
        ]);

        return str_starts_with($path, '/') ? $path : $config->root . '/' . $path;
    }

    public static function notaryProfile(): ?string
    {
        $profile = getenv(self::NOTARY_ENV);

        return \is_string($profile) && trim($profile) !== '' ? trim($profile) : null;
    }

    /** @param \Closure(string): void $log */
    public static function build(Config $config, string $app, string $output, Codesign $codesign, bool $style, \Closure $log): void
    {
        $macos   = $config->macos();
        $profile = self::notaryProfile();

        if ($profile !== null && $codesign->adHoc()) {
            throw new PackagerException(self::NOTARY_ENV . ' is set but ' . Codesign::IDENTITY_ENV . ' is not: notarisation needs a Developer ID signature.');
        }

        $work = Filesystem::tempDir('dmg');

        try {
            $stage = $work . '/stage';
            Filesystem::mkdir($stage);
            // ditto keeps the bundle exactly as signed (modes, symlinks, extended attributes).
            Process::mustRun(['ditto', $app, $stage . '/' . basename($app)]);

            if (!symlink('/Applications', $stage . '/Applications')) {
                throw new PackagerException('Cannot create the /Applications link.');
            }

            $kb    = (int) trim(explode("\t", Process::mustRun(['du', '-sk', $stage]))[0]);
            $image = $work . '/rw.dmg';
            Process::mustRun([
                'hdiutil', 'create', '-volname', $macos->volumeName, '-srcfolder', $stage, '-fs', 'HFS+',
                '-format', 'UDRW', '-size', (intdiv($kb, 1024) + 20) . 'm', '-ov', $image,
            ]);

            self::dress($config, $image, basename($app), $style, $log);

            Filesystem::mkdir(\dirname($output));

            if (is_file($output)) {
                unlink($output);
            }

            self::convert($image, $output, $log);
        } finally {
            Filesystem::remove($work);
        }

        $codesign->signImage($output);

        if ($profile !== null) {
            $log("Notarising (profile {$profile}); this can take a few minutes…");
            Process::mustRun(['xcrun', 'notarytool', 'submit', $output, '--keychain-profile', $profile, '--wait']);
            Process::mustRun(['xcrun', 'stapler', 'staple', $output]);
            Process::mustRun(['xcrun', 'stapler', 'validate', $output]);
            [$code, $out, $err] = Process::run(['spctl', '-a', '-vv', '-t', 'open', '--context', 'context:primary-signature', $output]);
            $log('spctl: ' . trim($out . $err));

            if ($code !== 0) {
                throw new PackagerException("Gatekeeper rejects the notarised image:\n" . trim($out . $err));
            }
        }

        Filesystem::write($output . '.sha256', hash_file('sha256', $output) . '  ' . basename($output) . "\n");
    }

    /** How many times `hdiutil convert` is tried when the image is still held (see {@see convert()}). */
    private const CONVERT_ATTEMPTS = 5;

    /**
     * Compresses the writable image into the read-only one.
     *
     * ⚠️ `hdiutil convert` can fail with "Resource temporarily unavailable" (EAGAIN) right after the
     * writable image was detached: `diskimages-helper` (or Spotlight indexing the volume) still holds
     * the file for a moment. Seen on GitHub's macOS runners, arm64 and Intel alike, about once in two
     * runs (grafida/grafida#92). So exactly that error is retried, after a growing pause; any other
     * failure, or the same one five times, is fatal as before.
     *
     * @param \Closure(string): void $log
     */
    private static function convert(string $image, string $output, \Closure $log): void
    {
        $command = ['hdiutil', 'convert', $image, '-format', 'UDZO', '-imagekey', 'zlib-level=9', '-o', $output];

        for ($attempt = 1; ; $attempt++) {
            [$code, $out, $err] = Process::run($command);

            if ($code === 0) {
                return;
            }

            if (is_file($output)) {
                unlink($output);
            }

            if ($attempt >= self::CONVERT_ATTEMPTS || !str_contains($out . $err, 'Resource temporarily unavailable')) {
                throw new PackagerException('hdiutil convert failed (' . $code . "):\n" . trim($err . "\n" . $out));
            }

            $log("hdiutil convert: the image is still in use; trying again ({$attempt}/" . (self::CONVERT_ATTEMPTS - 1) . ')');
            sleep($attempt * 2);
        }
    }

    /**
     * Mounts the writable image and gives it its Finder layout (when $style) and its volume icon.
     *
     * @param \Closure(string): void $log
     */
    private static function dress(Config $config, string $image, string $appName, bool $style, \Closure $log): void
    {
        $macos = $config->macos();

        if ($macos->volumeIcon === null && (!$style || $macos->dmgBackground === null)) {
            return;
        }

        $expected = '/Volumes/' . $macos->volumeName;

        if (file_exists($expected)) {
            $log("WARNING: a volume is already mounted at {$expected}; the image is left without its Finder layout and volume icon.");

            return;
        }

        $attach = Process::mustRun(['hdiutil', 'attach', '-readwrite', '-noverify', '-noautoopen', $image]);
        $mount  = preg_match('#(/Volumes/.+)$#m', $attach, $m) === 1 ? rtrim($m[1]) : null;

        try {
            if ($mount !== $expected) {
                $log('WARNING: the image mounted at ' . ($mount ?? '(nowhere)') . ", not {$expected}; it is left unstyled.");

                return;
            }

            if ($style && $macos->dmgBackground !== null) {
                self::style($config, $mount, $appName, $log);
            } elseif ($style) {
                $log('No macos.dmg.background: the image keeps Finder\'s default layout.');
            }

            if ($macos->volumeIcon !== null) {
                copy($macos->volumeIcon, $mount . '/.VolumeIcon.icns');
                self::setCustomIcon($mount);
            }
        } finally {
            if ($mount !== null) {
                Process::run(['sync']);
                [$code] = Process::run(['hdiutil', 'detach', $mount]);

                if ($code !== 0) {
                    Process::run(['hdiutil', 'detach', $mount, '-force']);
                }
            }
        }
    }

    /** @param \Closure(string): void $log */
    private static function style(Config $config, string $mount, string $appName, \Closure $log): void
    {
        $macos = $config->macos();
        $bg    = $macos->dmgBackground;
        \assert($bg !== null);
        $file  = 'background.' . strtolower(pathinfo($bg, \PATHINFO_EXTENSION));
        Filesystem::mkdir($mount . '/.background');
        copy($bg, $mount . '/.background/' . $file);

        [$w, $h] = $macos->window;
        $left    = 400;
        $top     = 150;
        $q       = static fn (string $s): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
        $script  = implode("\n", [
            'tell application "Finder"',
            // Finder sees a volume a moment after hdiutil has mounted it (about a second, measured).
            '  set waited to 0',
            '  repeat until (exists disk ' . $q($macos->volumeName) . ') or waited > 20',
            '    delay 0.5',
            '    set waited to waited + 0.5',
            '  end repeat',
            '  tell disk ' . $q($macos->volumeName),
            '    open',
            '    set current view of container window to icon view',
            '    set toolbar visible of container window to false',
            '    set statusbar visible of container window to false',
            "    set the bounds of container window to {{$left}, {$top}, " . ($left + $w) . ', ' . ($top + $h) . '}',
            '    set theViewOptions to the icon view options of container window',
            '    set arrangement of theViewOptions to not arranged',
            "    set icon size of theViewOptions to {$macos->iconSize}",
            "    set text size of theViewOptions to {$macos->textSize}",
            '    set background picture of theViewOptions to file ' . $q('.background:' . $file),
            '    set position of item ' . $q($appName) . " of container window to {{$macos->appPosition[0]}, {$macos->appPosition[1]}}",
            '    set position of item "Applications" of container window to {' . $macos->applicationsPosition[0] . ', ' . $macos->applicationsPosition[1] . '}',
            '    update without registering applications',
            '    delay 1',
            '    close',
            '  end tell',
            'end tell',
        ]);

        // perl's alarm: an Automation prompt nobody answers must not hang the build.
        [$code, $out, $err] = Process::run(['perl', '-e', 'alarm shift; exec @ARGV', (string) self::STYLE_TIMEOUT, 'osascript', '-'], $script);

        if ($code !== 0) {
            $log('WARNING: Finder could not lay out the image (' . trim($err . $out) . '); it stays plain. '
                . 'Grant this terminal the Automation permission for Finder to have the layout.');
            Filesystem::remove($mount . '/.background');

            return;
        }

        $log('Finder layout applied.');
    }

    /** Marks a volume as having a custom icon (`.VolumeIcon.icns`): Finder's kHasCustomIcon flag. */
    private static function setCustomIcon(string $mount): void
    {
        if (Process::exists('SetFile')) {
            Process::run(['SetFile', '-a', 'C', $mount]);

            return;
        }

        // FinderInfo: 32 bytes, the Finder flags big-endian at offset 8; kHasCustomIcon is 0x0400.
        Process::run(['xattr', '-wx', 'com.apple.FinderInfo', '0000000000000000' . '0400' . str_repeat('0', 44), $mount]);
    }
}
