<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager;

/** Runs a system tool (codesign, hdiutil, …) without a shell in between. */
final class Process
{
    /**
     * @param list<string> $command
     * @return array{int, string, string} exit status, stdout, stderr
     */
    public static function run(array $command, ?string $stdin = null): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!\is_resource($process)) {
            throw new PackagerException('Cannot run ' . $command[0] . '.');
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /**
     * Runs $command and returns its stdout; a non-zero exit is an error naming the command.
     *
     * @param list<string> $command
     */
    public static function mustRun(array $command, ?string $stdin = null): string
    {
        [$code, $out, $err] = self::run($command, $stdin);

        if ($code !== 0) {
            throw new PackagerException(implode(' ', array_map(self::quote(...), $command)) . " failed ({$code}):\n" . trim($err . "\n" . $out));
        }

        return $out;
    }

    /**
     * Runs $command with stdin read from $stdinFile and stdout written to $stdoutFile, so neither
     * passes through memory (an AppImage's squashfs is tens of megabytes). Only stderr is a pipe,
     * so there is nothing to deadlock on. A non-zero exit is an error naming the command.
     *
     * @param list<string> $command
     */
    public static function mustRunFiles(array $command, string $stdinFile, string $stdoutFile): void
    {
        $process = proc_open($command, [0 => ['file', $stdinFile, 'rb'], 1 => ['file', $stdoutFile, 'wb'], 2 => ['pipe', 'w']], $pipes);

        if (!\is_resource($process)) {
            throw new PackagerException('Cannot run ' . $command[0] . '.');
        }

        $err  = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            throw new PackagerException(implode(' ', array_map(self::quote(...), $command)) . " failed ({$code}):\n" . trim($err));
        }
    }

    public static function exists(string $tool): bool
    {
        [$code] = \PHP_OS_FAMILY === 'Windows'
            ? self::run(['where', '/q', $tool])
            : self::run(['/bin/sh', '-c', 'command -v "$0" >/dev/null', $tool]);

        return $code === 0;
    }

    private static function quote(string $arg): string
    {
        return preg_match('/^[A-Za-z0-9_\/.=:,+-]+$/', $arg) === 1 ? $arg : "'" . str_replace("'", "'\\''", $arg) . "'";
    }
}
