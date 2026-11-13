<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\Macos\Codesign;
use PHPUnit\Framework\TestCase;

/** `--no-sign`: nothing is signed, and nothing is run to find out. */
final class CodesignTest extends TestCase
{
    public function testUnsignedSignsNothingAndDoesNotTouchTheFile(): void
    {
        $signer = Codesign::unsigned();

        // codesign would fail on a file that does not exist; skipping must not even try.
        $signer->sign('/nonexistent/app', '/nonexistent/entitlements.plist');
        $signer->signImage('/nonexistent/app.dmg');

        self::assertTrue($signer->skip);
        self::assertSame('unsigned', $signer->describe());
    }

    public function testAnIdentityOrAdHocIsNotSkipped(): void
    {
        self::assertFalse((new Codesign(null))->skip);
        self::assertSame('ad hoc', (new Codesign(null))->describe());
        self::assertSame('Developer ID Application: X', (new Codesign('Developer ID Application: X'))->describe());
    }
}
