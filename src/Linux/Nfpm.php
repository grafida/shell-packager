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
use Grafida\ShellPackager\Fetcher;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Process;
use Grafida\ShellPackager\Runtime;

/**
 * nfpm (https://nfpm.goreleaser.com), the tool that writes the `.deb` and `.rpm`. It is a build
 * tool of the packager, not of the app, so its pin lives here, not in the app's configuration: tag
 * 2.49.0, one archive per build host, each with the SHA-256 of the bytes downloaded from the
 * release (and found equal to the release's checksums.txt, which is only a cross-check). It is
 * fetched, verified and cached by {@see Fetcher} like every other binary.
 *
 * `--local nfpm=PATH` (an unpinned nfpm binary) works as for the other runtimes.
 */
final class Nfpm
{
    public const VERSION = '2.49.0';

    private const BASE = 'https://github.com/goreleaser/nfpm/releases/download/v' . self::VERSION . '/nfpm_' . self::VERSION . '_';

    /** The pin for a build host: OS family (as `PHP_OS_FAMILY`) => the runtime for each host architecture. */
    public static function runtime(string $osFamily): Runtime
    {
        $pins = match ($osFamily) {
            'Darwin' => [
                Arch::Aarch64->value => ['Darwin_arm64.tar.gz', '27728af45782962ab59a8ff13ae041c7d955fe51dd94ab7883d9c5966a233f5d'],
                Arch::X86_64->value  => ['Darwin_x86_64.tar.gz', '182ba6c5ded85c87bf276dd80c0ecfd8a2f8b25f7db6ac5fb999557c5b4258ce'],
            ],
            'Linux' => [
                Arch::Aarch64->value => ['Linux_arm64.tar.gz', 'd4eee71839e92c68cbd3b101640bad32d2856fc551acae0635be9118e48c9d9c'],
                Arch::X86_64->value  => ['Linux_x86_64.tar.gz', '2afaa5ffb5e3493402e31e7c67a5a1fb42133e3f66fc315ca0051934125561c7'],
            ],
            default => throw new PackagerException("nfpm is pinned for macOS and Linux build hosts only, not {$osFamily}: build the .deb and .rpm on either."),
        };

        $urls   = [];
        $hashes = [];

        foreach ($pins as $arch => [$asset, $hash]) {
            $urls[$arch]   = self::BASE . $asset;
            $hashes[$arch] = $hash;
        }

        return new Runtime('nfpm', $urls, $hashes);
    }

    /**
     * A runnable, verified nfpm for this machine, extracted into $workDir (the archive is re-hashed
     * by the fetch on every use, so what runs is what was pinned).
     */
    public static function binary(Fetcher $fetcher, string $workDir): string
    {
        $runtime = self::runtime(\PHP_OS_FAMILY);
        $arch    = Arch::host() ?? throw new PackagerException('This machine is neither x86_64 nor aarch64, so there is no nfpm for it.');
        $path    = $fetcher->fetch($runtime, $arch);

        // A --local binary is used as it is.
        if ($fetcher->hasLocal('nfpm')) {
            return $path;
        }

        $dir = $workDir . '/nfpm';

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new PackagerException("Cannot create {$dir}.");
        }

        Process::mustRun(['tar', '-xzf', $path, '-C', $dir, 'nfpm']);
        $binary = $dir . '/nfpm';

        if (!is_file($binary)) {
            throw new PackagerException("The nfpm archive {$path} holds no 'nfpm' binary.");
        }

        chmod($binary, 0o755);

        return $binary;
    }
}
