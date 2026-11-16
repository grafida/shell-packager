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
     * A copy of the fixture's packaging configuration in the app root, with $runtimes in place of
     * its own, $linux merged over its linux section, and $shell in place of its shell block (null:
     * the fixture's own; false: none).
     *
     * @param array<string, mixed>       $runtimes
     * @param array<string, mixed>       $linux
     * @param array<string, mixed>|false|null $shell
     */
    public static function config(array $runtimes = [], array $linux = [], array|false|null $shell = null): Config
    {
        $data = json_decode(Filesystem::read(self::APP . '/shell-package.json'), true);
        \assert(\is_array($data) && \is_array($data['linux']));
        $data['runtimes'] = $runtimes;
        $data['linux']    = array_replace($data['linux'], $linux);

        if ($shell === false) {
            unset($data['shell']);
        } elseif ($shell !== null) {
            $data['shell'] = $shell;
        }

        $path = self::APP . '/shell-package.test-' . bin2hex(random_bytes(4)) . '.json';
        Filesystem::write($path, (string) json_encode($data));

        try {
            return Config::load($path);
        } finally {
            unlink($path);
        }
    }
}
