<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Phar\IniBlock;
use PHPUnit\Framework\TestCase;

final class IniBlockTest extends TestCase
{
    public function testTheByteLayoutIsMagicThenABigEndianLengthThenTheText(): void
    {
        $text = "memory_limit=512M\nopcache.enable=0\nopcache.enable_cli=1\nprecision=14\n";

        self::assertSame(
            "\xfd\xf6\x69\xe6" . "\x00\x00\x00" . \chr(\strlen($text)) . $text,
            IniBlock::encode(['memory_limit' => '512M', 'opcache.enable' => false, 'opcache.enable_cli' => true, 'precision' => 14])
        );
    }

    public function testNoSettingsMeansNoBlock(): void
    {
        self::assertSame('', IniBlock::encode([]));
        self::assertSame(['text' => '', 'length' => 0], IniBlock::decode('<?php echo 1;'));
    }

    public function testAValueThatIsNotABareWordIsSingleQuoted(): void
    {
        self::assertStringContainsString("error_log='/tmp/a log \${HOME}'\n", IniBlock::encode(['error_log' => '/tmp/a log ${HOME}']));
    }

    public function testDecodeIsTheInverse(): void
    {
        $block = IniBlock::encode(['memory_limit' => '1G']);
        $read  = IniBlock::decode($block . '<?php __HALT_COMPILER();');

        self::assertSame("memory_limit=1G\n", $read['text']);
        self::assertSame(\strlen($block), $read['length']);
    }

    public function testASingleQuoteCannotBeEncoded(): void
    {
        $this->expectException(PackagerException::class);
        IniBlock::encode(['error_log' => "it's"]);
    }

    public function testABadNameIsRefused(): void
    {
        $this->expectException(PackagerException::class);
        IniBlock::encode(["memory_limit\n" => '1G']);
    }
}
