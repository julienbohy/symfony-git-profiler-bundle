<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Cache;

use JulienBohy\GitProfilerBundle\Git\Cache\RemoteConfigFilter;
use JulienBohy\GitProfilerBundle\Git\Parser\GitConfigParser;
use JulienBohy\GitProfilerBundle\Git\Remote\RemoteUrlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RemoteConfigFilterTest extends TestCase
{
    public function testKeepsOnlyTheBranchRemotesAndTheRemoteUrls(): void
    {
        $output = $this->config([
            ['user.name', 'Ada'],
            ['user.email', 'ada@example.com'],
            ['http.https://github.com/.extraheader', 'AUTHORIZATION: basic c2VjcmV0'],
            ['credential.helper', 'store'],
            ['remote.origin.url', 'https://github.com/acme/repo.git'],
            ['remote.origin.fetch', '+refs/heads/*:refs/remotes/origin/*'],
            ['branch.main.remote', 'origin'],
            ['branch.main.merge', 'refs/heads/main'],
            ['core.bare', null],
        ]);

        self::assertSame(
            $this->config([
                ['remote.origin.url', 'https://github.com/acme/repo.git'],
                ['branch.main.remote', 'origin'],
            ]),
            (new RemoteConfigFilter())->filter($output),
        );
    }

    public function testAnEmptyConfigStaysEmpty(): void
    {
        self::assertSame('', (new RemoteConfigFilter())->filter(''));
    }

    #[DataProvider('urls')]
    public function testStripsTheUserinfoOfTheUrls(string $url, string $expected): void
    {
        $filtered = (new RemoteConfigFilter())->filter($this->config([['remote.origin.url', $url]]));

        self::assertSame($this->config([['remote.origin.url', $expected]]), $filtered);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable
    {
        yield 'user and password' => ['https://user:secret@github.com/acme/repo.git', 'https://github.com/acme/repo.git'];
        yield 'token' => ['https://ghp_token@github.com/acme/repo.git', 'https://github.com/acme/repo.git'];
        yield 'ssh user' => ['ssh://git@github.com:22/acme/repo.git', 'ssh://github.com:22/acme/repo.git'];
        yield 'scp-like' => ['git@github.com:acme/repo.git', 'github.com:acme/repo.git'];
        yield 'scp-like with password' => ['user:secret@github.com:acme/repo.git', 'github.com:acme/repo.git'];
        yield 'no userinfo' => ['https://github.com/acme/repo.git', 'https://github.com/acme/repo.git'];
        yield 'at sign in the path only' => ['https://github.com/acme/re@po.git', 'https://github.com/acme/re@po.git'];
        yield 'local path' => ['/srv/git/repo.git', '/srv/git/repo.git'];
    }

    #[DataProvider('urls')]
    public function testTheResolvedRemoteIsByteIdenticalWithOrWithoutTheFilter(string $url, string $unused): void
    {
        $output = $this->config([
            ['user.email', 'ada@example.com'],
            ['remote.origin.url', $url],
            ['branch.main.remote', 'origin'],
        ]);
        $parser = new GitConfigParser();
        $urlParser = new RemoteUrlParser();

        $full = $parser->remote($output, 'main');
        $filtered = $parser->remote((new RemoteConfigFilter())->filter($output), 'main');

        self::assertNotNull($full);
        self::assertNotNull($filtered);
        self::assertEquals($urlParser->parse($full->url), $urlParser->parse($filtered->url));
        self::assertSame($full->name, $filtered->name);
    }

    /**
     * @param list<array{string, ?string}> $entries
     */
    private function config(array $entries): string
    {
        return implode('', array_map(static fn (array $entry): string => $entry[0] . ($entry[1] !== null ? "\n" . $entry[1] : '') . "\0", $entries));
    }
}
