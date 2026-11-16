<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\Cli;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    public function testUpdateIsACommandThatNeedsAConfig(): void
    {
        self::assertSame(1, Cli::main(['shell-packager', 'update']));
        self::assertStringContainsString('shell-packager update --config=FILE', Cli::USAGE);
    }
}
