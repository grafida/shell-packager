<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\IncludeRule;
use Grafida\ShellPackager\PackagerException;
use PHPUnit\Framework\TestCase;

final class IncludeRuleTest extends TestCase
{
    public function testNameNotNameAndNotPath(): void
    {
        $rule = IncludeRule::fromArray(['directory' => 'vendor/', 'name' => '*.php', 'notName' => ['*Test.php'], 'notPath' => ['phpunit', 'a/b/']], 'r');

        self::assertSame('vendor', $rule->directory);
        self::assertTrue($rule->isVendor());
        self::assertTrue($rule->accepts('x/src/Thing.php'));
        self::assertFalse($rule->accepts('x/src/ThingTest.php'));
        self::assertFalse($rule->accepts('x/README.md'));
        self::assertFalse($rule->accepts('phpunit/src/Framework.php'));
        self::assertTrue($rule->accepts('phpunitx/src/Framework.php'));
        self::assertFalse($rule->accepts('a/b/c.php'));
    }

    public function testNoNameMeansEverything(): void
    {
        $rule = IncludeRule::fromArray(['directory' => 'assets'], 'r');

        self::assertFalse($rule->isVendor());
        self::assertTrue($rule->accepts('img/logo.svg'));
    }

    public function testADirectoryOutsideTheAppIsRefused(): void
    {
        $this->expectException(PackagerException::class);
        IncludeRule::fromArray(['directory' => '../elsewhere'], 'r');
    }
}
