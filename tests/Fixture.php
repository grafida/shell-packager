<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\Config;
use Grafida\ShellPackager\Filesystem;

/** The fixture app (tests/fixtures/app), with runtimes pinned to whatever the test supplies. */
final class Fixture
{
    public const APP = __DIR__ . '/fixtures/app';

    /**
     * A copy of the fixture's packaging configuration in the app root, with $runtimes merged in.
     *
     * @param array<string, array{url: string, sha256: array<string, string>}> $runtimes
     */
    public static function config(array $runtimes = []): Config
    {
        $data = json_decode(Filesystem::read(self::APP . '/shell-package.json'), true);
        \assert(\is_array($data));
        $data['runtimes'] = $runtimes;
        $path = self::APP . '/shell-package.test-' . bin2hex(random_bytes(4)) . '.json';
        Filesystem::write($path, (string) json_encode($data));

        try {
            return Config::load($path);
        } finally {
            unlink($path);
        }
    }
}
