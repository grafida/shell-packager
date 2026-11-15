<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Linux;

use Grafida\ShellPackager\Arch;
use Grafida\ShellPackager\Config;
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Process;

/**
 * The AppImage (grafida/grafida#87): the static type-2 runtime (an ELF) followed by a squashfs image
 * of the AppDir. For an app named `myapp` with manifest id `app.myapp` and worker `myapp-worker`:
 *
 * ```
 * AppRun                 the launcher (static); the runtime execs it
 * myapp-shell            the shell (the launcher execs it beside itself)
 * myapp-worker, .phar    the SFX stub and its payload, still two files
 * shell.json
 * app.myapp.desktop      desktop entry (Exec, Icon, StartupWMClass forced as in the tarball)
 * app.myapp.png          the largest icon, as the AppImage spec wants at the root
 * .DirIcon -> app.myapp.png
 * usr/share/icons/hicolor/<n>x<n>/apps/app.myapp.png
 * …                      linux.files
 * ```
 *
 * WebKitGTK and GTK are never bundled: the AppImage uses the system's, and the launcher's alert
 * says so when they are missing.
 *
 * WHY NOT appimagetool: it is a Linux ELF, and the packages are built on a Mac. An AppImage is
 * nothing but `runtime ++ squashfs`, so it is assembled here with Homebrew's `mksquashfs`
 * (formula `squashfs`); the only other thing appimagetool does that matters is writing the update
 * information into the runtime's `.upd_info` section, which {@see self::embedUpdateInformation()}
 * does. It also fills `.digest_md5`/`.sha256_sig` for signing, which this packager does not use.
 *
 * The squashfs is zstd-compressed: the pinned runtime (type2-runtime) is built with zstd and zlib.
 * Reproducible: entries are sorted by mksquashfs, every timestamp is the build time, and ownership
 * is root with no extended attributes, so nothing of the build host (user, group, xattrs such as
 * macOS's com.apple.*) leaks in.
 */
final class AppImage
{
    public const ELF_SECTION = '.upd_info';

    /** AppImage type 2 marker at offset 8 of the ELF header (the padding of e_ident). */
    public const MAGIC = "AI\x02";

    /** Fails early, before the expensive parts of a build, when mksquashfs is not installed. */
    public static function requireTools(): void
    {
        if (!Process::exists('mksquashfs')) {
            throw new PackagerException(
                "mksquashfs is required to build an AppImage and was not found.\n"
                . "  macOS: brew install squashfs      Debian/Ubuntu: apt install squashfs-tools"
            );
        }
    }

    /**
     * @param array{launcher: string, shell: string, sfx: string} $runtimes paths of verified binaries
     * @param string $runtime the verified type-2 runtime for the target architecture
     */
    public static function write(Config $config, array $runtimes, string $runtime, string $payload, string $output, int $mtime, Arch $arch): void
    {
        self::requireTools();

        if ($config->desktop === null || $config->icons === []) {
            throw new PackagerException('An AppImage needs linux.desktop and at least one linux.icons entry: the desktop entry and the icon live at the root of the AppDir.');
        }

        $options = $config->appImage ?? new AppImageConfig();
        $info    = $options->updateInformation === null ? null : strtr($options->updateInformation, [
            '{name}'    => $config->linuxName,
            '{arch}'    => $arch->value,
            '{debarch}' => $arch->debian(),
        ]);

        // The runtime is read and, if need be, patched before the squashfs is spent on.
        $elf = Filesystem::read($runtime);

        if (!str_starts_with($elf, "\x7fELF") || substr($elf, 8, 3) !== self::MAGIC) {
            throw new PackagerException("{$runtime} is not an AppImage type-2 runtime (no ELF header with AI\\x02 at offset 8).");
        }

        if ($info !== null) {
            $elf = self::embedUpdateInformation($elf, $info);
        }

        $work   = Filesystem::tempDir('appimage');
        $appDir = $work . '/AppDir';

        try {
            self::stage($config, $runtimes, $payload, $appDir, $mtime);

            $squashfs = $work . '/image.squashfs';
            // mksquashfs refuses -repro-time together with SOURCE_DATE_EPOCH, which it reads itself;
            // the time is passed explicitly (it is that variable's value, or the build time).
            Process::mustRun([
                'env', '-u', 'SOURCE_DATE_EPOCH', 'mksquashfs', $appDir, $squashfs,
                '-noappend', '-quiet', '-no-progress',
                '-comp', 'zstd', '-b', '128K',
                '-all-root', '-no-xattrs', '-root-mode', '0755',
                '-repro-time', (string) $mtime,
            ]);

            Filesystem::mkdir(\dirname($output));
            $part = $output . '.part';
            Filesystem::write($part, $elf);
            $in  = fopen($squashfs, 'rb');
            $out = fopen($part, 'ab');

            if ($in === false || $out === false || stream_copy_to_stream($in, $out) === false) {
                throw new PackagerException("Cannot append the squashfs image to {$part}.");
            }

            fclose($in);
            fclose($out);
            chmod($part, 0o755);
            rename($part, $output);
        } finally {
            Filesystem::remove($work);
        }

        Filesystem::write($output . '.sha256', hash_file('sha256', $output) . '  ' . basename($output) . "\n");
    }

    public static function outputPath(Config $config, Arch $arch, string $version): string
    {
        $path = strtr(($config->appImage ?? new AppImageConfig())->output, [
            '{name}'    => $config->linuxName,
            '{version}' => $version,
            '{arch}'    => $arch->value,
            '{debarch}' => $arch->debian(),
        ]);

        return str_starts_with($path, '/') ? $path : $config->root . '/' . $path;
    }

    /**
     * Writes the update information into the runtime's `.upd_info` section: the string, NUL-padded
     * to the section's size (1024 in the pinned runtime), which is how appimagetool does it and
     * what `--appimage-updateinformation` and AppImageUpdate read back. The section is found by
     * name in the ELF section headers, never by a fixed offset, so a new runtime moves nothing.
     */
    public static function embedUpdateInformation(string $elf, string $info): string
    {
        $section = self::findSection($elf, self::ELF_SECTION);

        if ($section === null) {
            throw new PackagerException('The runtime has no .upd_info section, so it cannot carry update information.');
        }

        [$offset, $size] = $section;

        if (\strlen($info) >= $size) {
            throw new PackagerException("The update information is " . \strlen($info) . " bytes; the runtime's .upd_info section holds {$size} (including a NUL).");
        }

        return substr($elf, 0, $offset) . str_pad($info, $size, "\0") . substr($elf, $offset + $size);
    }

    /** Reads the update information back out of an AppImage (or runtime); null when there is none. */
    public static function readUpdateInformation(string $elf): ?string
    {
        $section = self::findSection($elf, self::ELF_SECTION);

        if ($section === null) {
            return null;
        }

        $value = rtrim(substr($elf, $section[0], $section[1]), "\0");

        return $value === '' ? null : $value;
    }

    /**
     * Offset and size of a named section of a little-endian ELF64 file.
     *
     * @return array{int, int}|null
     */
    public static function findSection(string $elf, string $name): ?array
    {
        // ELFCLASS64, ELFDATA2LSB: x86_64 and aarch64 runtimes are both.
        if (\strlen($elf) < 64 || !str_starts_with($elf, "\x7fELF") || $elf[4] !== "\x02" || $elf[5] !== "\x01") {
            throw new PackagerException('Not a little-endian ELF64 file.');
        }

        $shoff = self::u64($elf, 0x28);
        $shentsize = self::unpackInt('v', $elf, 0x3a);
        $shnum     = self::unpackInt('v', $elf, 0x3c);
        $shstrndx  = self::unpackInt('v', $elf, 0x3e);

        if ($shoff === 0 || $shnum === 0 || $shentsize < 64 || $shoff + $shnum * $shentsize > \strlen($elf) || $shstrndx >= $shnum) {
            throw new PackagerException('The ELF section header table is missing or truncated.');
        }

        $header = static fn (int $i): array => [
            'name'   => self::u32($elf, $shoff + $i * $shentsize),
            'offset' => self::u64($elf, $shoff + $i * $shentsize + 0x18),
            'size'   => self::u64($elf, $shoff + $i * $shentsize + 0x20),
        ];
        $strings = $header($shstrndx);

        for ($i = 0; $i < $shnum; $i++) {
            $section = $header($i);
            $at      = $strings['offset'] + $section['name'];
            $end     = strpos($elf, "\0", $at);

            if ($end !== false && substr($elf, $at, $end - $at) === $name) {
                if ($section['offset'] + $section['size'] > \strlen($elf)) {
                    throw new PackagerException("The ELF section {$name} lies outside the file.");
                }

                return [$section['offset'], $section['size']];
            }
        }

        return null;
    }

    private static function u32(string $data, int $at): int
    {
        return self::unpackInt('V', $data, $at);
    }

    private static function u64(string $data, int $at): int
    {
        return self::unpackInt('P', $data, $at);
    }

    private static function unpackInt(string $format, string $data, int $at): int
    {
        $values = @unpack($format . 'value', $data, $at);

        if (!\is_array($values) || !isset($values['value']) || !\is_int($values['value'])) {
            throw new PackagerException('The ELF file is truncated.');
        }

        return $values['value'];
    }

    /**
     * @param array{launcher: string, shell: string, sfx: string} $runtimes
     */
    private static function stage(Config $config, array $runtimes, string $payload, string $dir, int $mtime): void
    {
        $put = static function (string $name, string $data, int $mode) use ($dir, $mtime): void {
            Filesystem::write("{$dir}/{$name}", $data);
            chmod("{$dir}/{$name}", $mode);
            touch("{$dir}/{$name}", $mtime);
        };

        Filesystem::mkdir($dir);
        $put('AppRun', Filesystem::read($runtimes['launcher']), 0o755);
        $put(LinuxTarball::SHELL_BINARY, Filesystem::read($runtimes['shell']), 0o755);
        $put($config->workerBinary, Filesystem::read($runtimes['sfx']), 0o755);
        $put($config->workerBinary . '.phar', Filesystem::read($payload), 0o644);
        $put('shell.json', Filesystem::read($config->manifestPath), 0o644);
        $put($config->appId . '.desktop', LinuxTarball::desktopEntry(Filesystem::read((string) $config->desktop), $config), 0o644);

        // Config sorts the icons by size.
        $icons   = $config->icons;
        $largest = Filesystem::read((string) end($icons));
        $put($config->appId . '.png', $largest, 0o644);

        foreach ($config->icons as $size => $icon) {
            $put("usr/share/icons/hicolor/{$size}x{$size}/apps/{$config->appId}.png", Filesystem::read($icon), 0o644);
        }

        foreach ($config->files as $name => $source) {
            if (\in_array($name, ['AppRun', 'usr', '.DirIcon'], true)) {
                throw new PackagerException("linux.files cannot provide '{$name}': the AppImage uses that name itself.");
            }

            $put($name, Filesystem::read($source), 0o644);
        }

        symlink($config->appId . '.png', "{$dir}/.DirIcon");

        // Directories: explicit modes (umask-independent), deepest first so a parent's mtime is not moved.
        $dirs = [$dir];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($it as $info) {
            if ($info instanceof \SplFileInfo && $info->isDir() && !$info->isLink()) {
                $dirs[] = $info->getPathname();
            }
        }

        foreach (array_reverse($dirs) as $d) {
            chmod($d, 0o755);
            touch($d, $mtime);
        }
    }
}
