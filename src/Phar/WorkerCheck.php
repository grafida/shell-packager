<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Phar;

use Grafida\ShellPackager\Arch;
use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;

/**
 * Runs the packaged worker before it ships, when this machine can (Linux, same architecture):
 *
 * - **the ini probe:** a tiny PHAR behind the same extra-INI block, on the same stub, reports
 *   `ini_get()` for every key; each must be what was asked for. This is what catches a block
 *   phpmicro silently ignored.
 * - **the handshake:** the real payload on the real stub must send protocol v1's `ready` frame on
 *   stdout and exit when its stdin closes. Every shell worker speaks the protocol, so this holds for
 *   any app; it proves the PHAR, its autoloader and the app's bootstrap all load from `phar://`.
 *
 * Both run in a throwaway directory with every per-user directory pointed into it, so the worker
 * never touches the real user's data.
 */
final class WorkerCheck
{
    private const READY_TIMEOUT = 30.0;

    public static function canRun(Arch $arch): bool
    {
        return \PHP_OS_FAMILY === 'Linux' && Arch::host() === $arch;
    }

    /** @param array<string, bool|int|float|string> $ini */
    public static function iniProbe(string $sfx, array $ini): void
    {
        if ($ini === []) {
            return;
        }

        $work = Filesystem::tempDir('ini-probe');

        try {
            $phar = $work . '/probe-src.phar';
            $p    = new \Phar($phar, 0, 'probe.phar');
            $p->addFromString('probe.php', '<?php echo json_encode(array_map("ini_get", ' . var_export(array_combine(array_keys($ini), array_keys($ini)), true) . '));');
            $p->setStub("<?php Phar::mapPhar('probe.phar'); require 'phar://probe.phar/probe.php'; __HALT_COMPILER();");
            unset($p);

            Filesystem::write($work . '/probe.phar', IniBlock::encode($ini) . Filesystem::read($phar));
            copy($sfx, $work . '/probe');
            chmod($work . '/probe', 0o755);

            [$code, $out, $err] = self::run([$work . '/probe'], $work, '');
            $actual             = json_decode($out, true);

            if ($code !== 0 || !\is_array($actual)) {
                throw new PackagerException("The ini probe did not run (exit {$code}):\n{$out}{$err}");
            }

            foreach ($ini as $key => $expected) {
                $got = $actual[$key] ?? false;

                if (!self::sameIni($expected, $got)) {
                    throw new PackagerException(\sprintf(
                        "The SFX did not apply the extra-INI block: %s is %s, expected %s.",
                        $key,
                        var_export($got, true),
                        var_export($expected, true)
                    ));
                }
            }
        } finally {
            Filesystem::remove($work);
        }
    }

    /** Starts $sfx + $payload as `$name` / `$name.phar` and waits for `ready`; returns its header. */
    public static function handshake(string $sfx, string $payload, string $name): string
    {
        $work = Filesystem::tempDir('handshake');

        try {
            copy($sfx, $work . '/' . $name);
            chmod($work . '/' . $name, 0o755);
            copy($payload, $work . '/' . $name . '.phar');

            $process = proc_open(
                [$work . '/' . $name],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $work . '/stderr', 'w']],
                $pipes,
                $work,
                self::isolatedEnv($work)
            );

            if (!\is_resource($process)) {
                throw new PackagerException('Cannot start the packaged worker.');
            }

            stream_set_blocking($pipes[1], false);
            $buffer   = '';
            $deadline = microtime(true) + self::READY_TIMEOUT;
            $header   = null;

            while (microtime(true) < $deadline) {
                $read   = [$pipes[1]];
                $write  = null;
                $except = null;

                if (stream_select($read, $write, $except, 0, 200000) > 0) {
                    $chunk = fread($pipes[1], 65536);

                    if ($chunk === '' && feof($pipes[1])) {
                        break;
                    }

                    $buffer .= (string) $chunk;
                }

                if (\strlen($buffer) >= 8) {
                    if (!str_starts_with($buffer, 'GSF1')) {
                        break;
                    }

                    /** @var array{1: int} $length */
                    $length = unpack('V', substr($buffer, 4, 4));

                    if (\strlen($buffer) >= 8 + $length[1]) {
                        $header = substr($buffer, 8, $length[1]);

                        break;
                    }
                }
            }

            fclose($pipes[0]);
            // Drain stdout while waiting, so a worker that writes on its way out cannot block.
            $exited = self::waitExit($process, 10.0, $pipes[1]);
            fclose($pipes[1]);
            $exited ? proc_close($process) : self::kill($process);
            $stderr = is_file($work . '/stderr') ? Filesystem::read($work . '/stderr') : '';
            $tail   = implode("\n", \array_slice(explode("\n", trim($stderr)), -20));

            if ($header === null) {
                throw new PackagerException(
                    "The packaged worker sent no ready frame within " . self::READY_TIMEOUT . " s.\n"
                    . ($buffer !== '' ? 'stdout began with: ' . json_encode(substr($buffer, 0, 80)) . "\n" : '')
                    . "stderr:\n" . $tail
                );
            }

            $decoded = json_decode($header, true);

            if (!\is_array($decoded) || ($decoded['type'] ?? null) !== 'ready' || ($decoded['protocol'] ?? null) !== 1) {
                throw new PackagerException("The packaged worker's first frame is not a protocol 1 ready: {$header}");
            }

            if (!$exited) {
                throw new PackagerException("The packaged worker did not exit within 10 s of its stdin closing.\nstderr:\n{$tail}");
            }

            return $header;
        } finally {
            Filesystem::remove($work);
        }
    }

    private static function sameIni(bool|int|float|string $expected, mixed $actual): bool
    {
        if (!\is_string($actual)) {
            return false;
        }

        if (\is_bool($expected)) {
            return \in_array(strtolower($actual), $expected ? ['1', 'on', 'true', 'yes'] : ['', '0', 'off', 'false', 'no'], true);
        }

        return $actual === (string) $expected;
    }

    /**
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private static function run(array $command, string $cwd, string $stdin): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, self::isolatedEnv($cwd));

        if (!\is_resource($process)) {
            throw new PackagerException('Cannot run ' . $command[0]);
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /**
     * @param resource $process
     * @param resource $stdout
     */
    private static function waitExit($process, float $seconds, $stdout): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (!proc_get_status($process)['running']) {
                return true;
            }

            fread($stdout, 65536);
            usleep(50000);
        }

        return false;
    }

    /** @param resource $process */
    private static function kill($process): void
    {
        proc_terminate($process, 9);
        proc_close($process);
    }

    /** @return array<string, string> */
    private static function isolatedEnv(string $dir): array
    {
        $path = getenv('PATH');

        return [
            'PATH'            => \is_string($path) ? $path : '/usr/bin:/bin',
            'HOME'            => $dir . '/home',
            'XDG_DATA_HOME'   => $dir . '/home/data',
            'XDG_CONFIG_HOME' => $dir . '/home/config',
            'XDG_CACHE_HOME'  => $dir . '/home/cache',
            'XDG_STATE_HOME'  => $dir . '/home/state',
            'LANG'            => 'C.UTF-8',
        ];
    }
}
