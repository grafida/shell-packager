<?php

/**
 * Grafida Shell — packaging tooling.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 */

declare(strict_types=1);

namespace Grafida\ShellPackager\Tests;

use Grafida\ShellPackager\Filesystem;
use Grafida\ShellPackager\PackagerException;
use Grafida\ShellPackager\Process;
use Grafida\ShellPackager\Shell\GhReleaseSource;
use Grafida\ShellPackager\Shell\HttpReleaseSource;
use Grafida\ShellPackager\Shell\Release;
use Grafida\ShellPackager\Shell\ReleaseSources;
use PHPUnit\Framework\TestCase;

/**
 * The release listing, from GitHub's REST payload (tests/fixtures/releases holds the real 0.4.2
 * release, as `gh api` returns it) and from `gh api --jq`'s one-release-per-line output.
 * SHELL_PACKAGER_TEST_GITHUB=1 also lists the real grafida/shell, through gh and through curl.
 */
final class ReleaseSourceTest extends TestCase
{
    public const string FIXTURE = __DIR__ . '/fixtures/releases/grafida-shell-0.4.2.json';

    public function testTheReal042ReleaseParses(): void
    {
        $releases = HttpReleaseSource::parse(Filesystem::read(self::FIXTURE));

        self::assertCount(1, $releases);
        self::assertSame('0.4.2', $releases[0]->tag);
        self::assertFalse($releases[0]->draft);
        self::assertCount(14, $releases[0]->assets);

        $asset = $releases[0]->assets['grafida-shell-macos-x86_64'];
        self::assertSame('grafida-shell-macos-x86_64', $asset->name);
        self::assertSame('cc4dfcfed58f04bc55751384b66220c7f73d29d72b5724642767220ffee1c855', $asset->sha256);
        self::assertSame('https://github.com/grafida/shell/releases/download/0.4.2/grafida-shell-macos-x86_64', $asset->url);
    }

    public function testGhOutputIsOneReleasePerLine(): void
    {
        $lines = '{"tag_name":"0.4.2","draft":false,"assets":[{"name":"a","browser_download_url":"https://x/a","digest":"sha256:' . str_repeat('a', 64) . '"}]}' . "\n"
            . '{"tag_name":"0.5.0","draft":true,"assets":[]}' . "\n\n";
        $releases = GhReleaseSource::parse($lines);

        self::assertCount(2, $releases);
        self::assertSame('0.4.2', $releases[0]->tag);
        self::assertSame(str_repeat('a', 64), $releases[0]->assets['a']->sha256);
        self::assertTrue($releases[1]->draft);
        self::assertSame([], $releases[1]->assets);
    }

    public function testAnAssetWithoutADigestHasNone(): void
    {
        $release = Release::fromApi(['tag_name' => '1.0.0', 'draft' => false, 'assets' => [['name' => 'a', 'browser_download_url' => 'https://x/a', 'digest' => null]]]);
        self::assertNull($release->assets['a']->sha256);

        $this->expectException(PackagerException::class);
        $this->expectExceptionMessage('digest');
        Release::fromApi(['tag_name' => '1.0.0', 'draft' => false, 'assets' => [['name' => 'a', 'browser_download_url' => 'https://x/a', 'digest' => 'md5:abc']]]);
    }

    public function testAMalformedReleaseIsRefused(): void
    {
        $this->expectException(PackagerException::class);
        Release::fromApi(['tag_name' => 5, 'assets' => []]);
    }

    public function testNextPageFollowsTheLinkHeader(): void
    {
        $link = 'link: <https://api.github.com/repositories/1/releases?per_page=100&page=2>; rel="next", <https://api.github.com/repositories/1/releases?per_page=100&page=5>; rel="last"';

        self::assertSame('https://api.github.com/repositories/1/releases?per_page=100&page=2', HttpReleaseSource::nextPage(['HTTP/2 200', $link]));
        self::assertSame('https://api.github.com/repositories/1/releases?per_page=100&page=2', HttpReleaseSource::nextPage([str_replace('link:', 'Link:', $link)]));
        self::assertNull(HttpReleaseSource::nextPage(['HTTP/2 200', 'content-type: application/json']));
        self::assertNull(HttpReleaseSource::nextPage(['link: <https://api.github.com/x?page=1>; rel="prev"']));
    }

    public function testRateLimitSaysWhatToDo(): void
    {
        $limited = HttpReleaseSource::failure('grafida/shell', 403, ['HTTP/2 403', 'x-ratelimit-remaining: 0'], '{}');
        self::assertStringContainsString('GH_TOKEN', $limited->getMessage());
        self::assertStringContainsString('gh auth login', $limited->getMessage());

        self::assertStringContainsString('GH_TOKEN', HttpReleaseSource::failure('grafida/shell', 429, [], '')->getMessage());

        $missing = HttpReleaseSource::failure('grafida/nope', 404, [], '');
        self::assertStringContainsString('grafida/nope', $missing->getMessage());
        self::assertStringContainsString('public', $missing->getMessage());

        self::assertStringContainsString('500', HttpReleaseSource::failure('grafida/shell', 500, [], 'oops')->getMessage());
    }

    public function testDetectFallsBackToCurlWhenGhCannotBeUsed(): void
    {
        self::assertInstanceOf(HttpReleaseSource::class, ReleaseSources::detect(static fn (): bool => false));
        self::assertInstanceOf(GhReleaseSource::class, ReleaseSources::detect(static fn (): bool => true));
    }

    public function testTheRealRepository(): void
    {
        if (getenv('SHELL_PACKAGER_TEST_GITHUB') !== '1') {
            self::markTestSkipped('SHELL_PACKAGER_TEST_GITHUB=1 lists the real grafida/shell releases.');
        }

        $expected = HttpReleaseSource::parse(Filesystem::read(self::FIXTURE))[0];
        $sources  = [new HttpReleaseSource(null)];

        if (Process::exists('gh') && Process::run(['gh', 'auth', 'status'])[0] === 0) {
            $sources[] = new GhReleaseSource();
        }

        foreach ($sources as $source) {
            $found = null;

            foreach ($source->releases('grafida/shell') as $release) {
                if ($release->tag === '0.4.2') {
                    $found = $release;
                }
            }

            self::assertNotNull($found, $source->describe() . ' lists 0.4.2');
            self::assertEquals($expected, $found, $source->describe());
        }
    }
}
