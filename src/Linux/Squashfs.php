<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Linux;

use Grafida\ShellPackager\Archive\TarWriter;
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Process;

/**
 * Makes the AppImage's squashfs (grafida/shell-packager#1). The packager has no squashfs writer of
 * its own, so `mksquashfs` does it, found in one of two places (`linux.appimage.squashfs`):
 *
 * - **host**: the `mksquashfs` on PATH (Homebrew `squashfs`, apt `squashfs-tools`).
 * - **docker**: `mksquashfs` 4.6 or later in a container, for a host without it (Windows above all).
 *   The image is built locally from `resources/docker/squashfs.Dockerfile`, or is
 *   `linux.appimage.docker.image` (pinned by digest; it needs `sh`, `mktemp`, `cat` and mksquashfs
 *   4.6+). The image comes back on stdout: nothing is bind-mounted, so there is no host path to
 *   translate. The container runs with `--network none`.
 * - **auto** (the default): host when `mksquashfs` is on PATH, otherwise docker when the `docker`
 *   command is there *and* its daemon answers, otherwise an error naming both.
 *
 * WHY A TAR: mksquashfs 4.6+ reads the AppDir as a plain tar on stdin (`mksquashfs - … -tar`), which
 * the packager writes with every mode, owner and time fixed. Nothing then depends on the build host's
 * filesystem: a symlink is 0755 on macOS but 0777 on Linux (so `.DirIcon` alone made a Mac's image
 * differ from a Linux one's), NTFS has no modes at all, and Docker Desktop shows a mount as 0777. So
 * with the same mksquashfs and zstd versions, a Mac, a Linux host and the container give the same
 * bytes (AppImageTest checks the host against the docker route through a fake `docker`, and against
 * the real one with SHELL_PACKAGER_TEST_DOCKER). Only a host mksquashfs older than 4.6 (4.5 on
 * Ubuntu 22.04, a Linux host) still gets a staged directory.
 */
final readonly class Squashfs
{
    /** Where the locally built image is tagged; the tag is the Dockerfile's SHA-256 (first 16 digits). */
    public const LOCAL_IMAGE = 'grafida-shell-packager-squashfs';

    /** `-tar` (a tar archive on stdin) arrived in squashfs-tools 4.6. */
    public const TAR_MINIMUM = [4, 6];

    /** @param bool $tar whether mksquashfs reads the AppDir as a tar (4.6+) rather than a directory */
    private function __construct(public string $strategy, public ?string $image, public bool $tar)
    {
    }

    /** The host's mksquashfs; $tar null asks it whether it reads a tar. */
    public static function host(?bool $tar = null): self
    {
        return new self('host', null, $tar ?? self::readsTar(Process::run(['mksquashfs', '-version'])[1]));
    }

    /** A Docker strategy for an image already known to be usable (see {@see self::resolve()}). */
    public static function docker(string $image): self
    {
        return new self('docker', $image, true);
    }

    /** Whether a `mksquashfs -version` output names 4.6 or later. */
    public static function readsTar(string $version): bool
    {
        return preg_match('/mksquashfs version (\d+)\.(\d+)/', $version, $m) === 1 && [(int) $m[1], (int) $m[2]] >= self::TAR_MINIMUM;
    }

    /**
     * Picks the strategy and, for Docker, makes sure its image exists and has a usable mksquashfs.
     * Called before anything is downloaded or built, so a missing tool costs nothing.
     *
     * @param callable(string): void $say
     */
    public static function resolve(AppImageConfig $config, callable $say): self
    {
        $hasHost = Process::exists('mksquashfs');

        if ($config->squashfs === 'host' || ($config->squashfs === 'auto' && $hasHost)) {
            if (!$hasHost) {
                throw new PackagerException(
                    "linux.appimage.squashfs is \"host\", and mksquashfs was not found.\n"
                    . "  macOS: brew install squashfs      Debian/Ubuntu: apt install squashfs-tools\n"
                    . '  Or set linux.appimage.squashfs to "docker" (or "auto") to run it in a container.'
                );
            }

            $version = Process::run(['mksquashfs', '-version'])[1];
            $say('squashfs: host, ' . self::firstLine($version));

            return self::host(self::readsTar($version));
        }

        if (!Process::exists('docker')) {
            throw new PackagerException(
                $config->squashfs === 'docker'
                    ? 'linux.appimage.squashfs is "docker", and the docker command was not found.'
                    : "An AppImage needs mksquashfs, and neither it nor docker was found. Either:\n"
                    . "  - install squashfs-tools (macOS: brew install squashfs; Debian/Ubuntu: apt install squashfs-tools), or\n"
                    . '  - install Docker, and the packager runs mksquashfs in a container.'
            );
        }

        [$code, , $err] = Process::run(['docker', 'version', '--format', '{{.Server.Version}}']);

        if ($code !== 0) {
            throw new PackagerException(
                "An AppImage needs mksquashfs" . ($config->squashfs === 'auto' ? ', which is not on PATH,' : '')
                . " and Docker's daemon is not answering (is Docker Desktop running?):\n  " . trim($err)
            );
        }

        $image = $config->dockerImage ?? self::localImage($say);

        if ($config->dockerImage !== null) {
            [$present] = Process::run(['docker', 'image', 'inspect', $image]);

            if ($present !== 0) {
                $say("Pulling {$image}");
                Process::mustRun(['docker', 'pull', $image]);
            }
        }

        $version = Process::mustRun(['docker', 'run', '--rm', '--network', 'none', $image, 'mksquashfs', '-version']);

        if (!self::readsTar($version)) {
            throw new PackagerException("{$image} needs mksquashfs " . implode('.', self::TAR_MINIMUM) . " or later (it reads the AppDir as a tar); it says:\n  " . trim($version));
        }

        $say('squashfs: docker, ' . $image . ' (' . self::firstLine($version) . ')');

        return self::docker($image);
    }

    /**
     * The image built from the packager's own Dockerfile, built now if this machine does not have it.
     *
     * @param callable(string): void $say
     */
    public static function localImage(callable $say): string
    {
        $dockerfile = self::dockerfile();
        $image      = self::LOCAL_IMAGE . ':' . substr(hash('sha256', Filesystem::read($dockerfile)), 0, 16);
        [$present]  = Process::run(['docker', 'image', 'inspect', $image]);

        if ($present !== 0) {
            $say("Building {$image} (once per machine) from {$dockerfile}");
            // No build context: the Dockerfile on stdin is all there is.
            Process::mustRun(['docker', 'build', '--tag', $image, '-'], Filesystem::read($dockerfile));
        }

        return $image;
    }

    private static function firstLine(string $text): string
    {
        return explode("\n", trim($text))[0];
    }

    public static function dockerfile(): string
    {
        return \dirname(__DIR__, 2) . '/resources/docker/squashfs.Dockerfile';
    }

    /**
     * The options both strategies give mksquashfs. mksquashfs reads SOURCE_DATE_EPOCH itself and then
     * refuses explicit times, so the host run unsets it (the container has none) and the time is
     * passed explicitly. -repro-time is not used: squashfs-tools 4.5 (Ubuntu 22.04) does not know
     * it, and these three exist from 4.4 on. zstd: the pinned type-2 runtime reads zstd and zlib.
     *
     * @return list<string>
     */
    public static function options(int $mtime): array
    {
        return [
            '-noappend', '-quiet', '-no-progress',
            '-comp', 'zstd', '-b', '128K',
            '-all-root', '-no-xattrs', '-root-mode', '0755',
            '-mkfs-time', (string) $mtime, '-all-time', (string) $mtime, '-root-time', (string) $mtime,
        ];
    }

    /**
     * Writes the squashfs of $entries to $output.
     *
     * @param list<AppDirEntry> $entries
     */
    public function build(array $entries, string $output, int $mtime, string $work): void
    {
        if (!$this->tar) {
            $dir = $work . '/AppDir';
            self::stage($entries, $dir, $mtime);
            Process::mustRun(['env', '-u', 'SOURCE_DATE_EPOCH', 'mksquashfs', $dir, $output, ...self::options($mtime)]);

            return;
        }

        $tar    = $work . '/AppDir.tar';
        $writer = new TarWriter($tar, $mtime, gzip: false);

        foreach ($entries as $entry) {
            match ($entry->type) {
                AppDirEntry::DIRECTORY => $writer->directory($entry->name, $entry->mode),
                AppDirEntry::FILE      => $writer->file($entry->name, $entry->content, $entry->mode),
                AppDirEntry::DATA      => $writer->data($entry->name, $entry->content, $entry->mode),
                AppDirEntry::SYMLINK   => $writer->symlink($entry->name, $entry->content),
            };
        }

        $writer->close();

        if ($this->strategy === 'host') {
            Process::mustRunFiles(['env', '-u', 'SOURCE_DATE_EPOCH', 'mksquashfs', '-', $output, '-tar', ...self::options($mtime)], $tar, $work . '/mksquashfs.out');

            return;
        }

        // mksquashfs seeks in its output, so it writes a file inside the container, which is then
        // streamed out. Its own messages go to stderr, keeping stdout for the image alone.
        $script = 't=$(mktemp -d) && mksquashfs - "$t/image" -tar "$@" >&2 && cat "$t/image"';
        Process::mustRunFiles(
            ['docker', 'run', '--rm', '-i', '--network', 'none', (string) $this->image, 'sh', '-c', $script, 'sh', ...self::options($mtime)],
            $tar,
            $output,
        );
    }

    /**
     * The AppDir as a directory: explicit modes (umask-independent) and times.
     *
     * @param list<AppDirEntry> $entries
     */
    private static function stage(array $entries, string $dir, int $mtime): void
    {
        Filesystem::mkdir($dir);
        $dirs = [$dir];

        foreach ($entries as $entry) {
            $path = "{$dir}/{$entry->name}";

            switch ($entry->type) {
                case AppDirEntry::DIRECTORY:
                    Filesystem::mkdir($path);
                    $dirs[] = $path;
                    break;
                case AppDirEntry::SYMLINK:
                    symlink($entry->content, $path);
                    break;
                default:
                    Filesystem::write($path, $entry->type === AppDirEntry::FILE ? Filesystem::read($entry->content) : $entry->content);
                    chmod($path, $entry->mode);
                    touch($path, $mtime);
            }
        }

        // Deepest first, so setting a directory's time is not undone by touching its contents.
        foreach (array_reverse($dirs) as $d) {
            chmod($d, 0o755);
            touch($d, $mtime);
        }
    }
}
