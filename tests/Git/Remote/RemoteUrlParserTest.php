<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Remote;

use JulienBohy\GitProfilerBundle\Git\Remote\GitProvider;
use JulienBohy\GitProfilerBundle\Git\Remote\RemoteUrlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RemoteUrlParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, GitProvider, string}>
     */
    public static function webUrls(): iterable
    {
        yield 'scp-like github' => ['git@github.com:owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'scp-like without suffix' => ['git@github.com:owner/repo', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'scp-like without user' => ['github.com:owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'scp-like with another user' => ['deploy@github.com:owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'scp-like with absolute path' => ['git@github.com:/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'scp-like trailing slash' => ['git@github.com:owner/repo.git/', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'https' => ['https://github.com/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'https without suffix' => ['https://github.com/owner/repo', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'https trailing slash' => ['https://github.com/owner/repo/', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'https suffix and trailing slash' => ['https://github.com/owner/repo.git/', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'https with user' => ['https://octocat@github.com/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'https with user and password' => ['https://octocat:s3cr3t@github.com/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'https with token' => ['https://ghp_TOKEN123@github.com/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'https with an @ in the password' => ['https://user:p@ss@github.com/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'https oauth2 token on gitlab' => ['https://oauth2:glpat-abc@gitlab.com/group/repo.git', GitProvider::GitLab, 'https://gitlab.com/group/repo'];
        yield 'http is kept' => ['http://gitlab.example.com/group/repo.git', GitProvider::GitLab, 'http://gitlab.example.com/group/repo'];
        yield 'https keeps its port' => ['https://gitlab.example.com:8443/group/repo.git', GitProvider::GitLab, 'https://gitlab.example.com:8443/group/repo'];
        yield 'https with an empty port' => ['https://github.com:/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'http keeps its port' => ['http://gitlab.local:8080/group/repo', GitProvider::GitLab, 'http://gitlab.local:8080/group/repo'];
        yield 'ssh' => ['ssh://git@github.com/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'ssh without user' => ['ssh://github.com/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'ssh port is dropped' => ['ssh://git@gitlab.example.com:2222/group/repo.git', GitProvider::GitLab, 'https://gitlab.example.com/group/repo'];
        yield 'ssh with an empty port' => ['ssh://git@github.com:/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'git protocol' => ['git://github.com/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'git protocol port is dropped' => ['git://gitlab.example.com:9418/group/repo.git', GitProvider::GitLab, 'https://gitlab.example.com/group/repo'];
        yield 'git+ssh' => ['git+ssh://git@github.com/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'ssh+git' => ['ssh+git://git@github.com/owner/repo.git', GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'host is lower-cased' => ['git@GitHub.COM:Owner/Repo.git', GitProvider::GitHub, 'https://github.com/Owner/Repo'];
        yield 'surrounding whitespace' => ["  git@github.com:owner/repo.git\n", GitProvider::GitHub, 'https://github.com/owner/repo'];
        yield 'dots, dashes and underscores' => ['git@github.com:my-org/my_repo.js.git', GitProvider::GitHub, 'https://github.com/my-org/my_repo.js'];
        yield 'a dot-git inside a name is kept' => ['git@github.com:owner/repo.github.io.git', GitProvider::GitHub, 'https://github.com/owner/repo.github.io'];
        yield 'gitlab.com' => ['git@gitlab.com:group/repo.git', GitProvider::GitLab, 'https://gitlab.com/group/repo'];
        yield 'gitlab nested groups' => ['git@gitlab.com:group/sub/deeper/repo.git', GitProvider::GitLab, 'https://gitlab.com/group/sub/deeper/repo'];
        yield 'gitlab nested groups over https' => ['https://user:pw@gitlab.com/group/sub/repo.git', GitProvider::GitLab, 'https://gitlab.com/group/sub/repo'];
        yield 'self-hosted gitlab' => ['git@gitlab.example.com:team/app.git', GitProvider::GitLab, 'https://gitlab.example.com/team/app'];
        yield 'gitlab in the host name' => ['git@code.mygitlab.io:team/app.git', GitProvider::GitLab, 'https://code.mygitlab.io/team/app'];
        yield 'bitbucket' => ['git@bitbucket.org:workspace/repo.git', GitProvider::Bitbucket, 'https://bitbucket.org/workspace/repo'];
        yield 'bitbucket https with user' => ['https://user@bitbucket.org/workspace/repo.git', GitProvider::Bitbucket, 'https://bitbucket.org/workspace/repo'];
    }

    #[DataProvider('webUrls')]
    public function testNormalisesTheRemoteUrl(string $url, GitProvider $provider, string $webUrl): void
    {
        $repository = (new RemoteUrlParser())->parse($url);

        self::assertNotNull($repository);
        self::assertSame($provider, $repository->provider);
        self::assertSame($webUrl, $repository->webUrl);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown host' => ['git@example.com:owner/repo.git'];
        yield 'unknown host over https' => ['https://git.example.org/owner/repo.git'];
        yield 'local absolute path' => ['/srv/git/repo.git'];
        yield 'local relative path' => ['../repo.git'];
        yield 'file url' => ['file:///srv/git/repo.git'];
        yield 'windows path' => ['C:/repos/repo.git'];
        yield 'unsupported scheme' => ['ftp://github.com/owner/repo.git'];
        yield 'no path' => ['https://github.com'];
        yield 'no path with slash' => ['https://github.com/'];
        yield 'github needs owner and repo' => ['https://github.com/owner'];
        yield 'github does not nest' => ['https://github.com/owner/sub/repo.git'];
        yield 'bitbucket does not nest' => ['git@bitbucket.org:a/b/c.git'];
        yield 'gitlab needs a group' => ['https://gitlab.com/repo.git'];
        yield 'empty segment' => ['https://github.com/owner//repo.git'];
        yield 'only a suffix' => ['https://github.com/owner/.git'];
        yield 'dot segment' => ['https://github.com/owner/..'];
        yield 'single dot segment' => ['https://github.com/./repo'];
        yield 'query string' => ['https://github.com/owner/repo.git?x=1'];
        yield 'fragment' => ['https://github.com/owner/repo#readme'];
        yield 'percent-encoded segment' => ['https://github.com/owner/re%20po'];
        yield 'quote in the path' => ['https://github.com/owner/re"po'];
        yield 'space in the path' => ['git@github.com:owner/re po.git'];
        yield 'raw slash in the password' => ['https://user:pa/ss@github.com/owner/repo.git'];
        yield 'host with odd characters' => ['https://gi"thub.com/owner/repo.git'];
        yield 'ipv6 host' => ['https://[::1]/owner/repo.git'];
        yield 'garbage before the url' => ['xhttps://github.com/owner/repo.git'];
        yield 'garbage after a newline (url)' => ["https://github.com/owner/repo.git\nrm -rf"];
        yield 'garbage after a newline (scp)' => ["git@github.com:owner/repo.git\nfoo"];
        yield 'garbage before the scp address' => ["x\ngit@github.com:owner/repo.git"];
        yield 'non-numeric port' => ['https://github.com:abc/owner/repo.git'];
        yield 'colon-slash-slash after an scp host' => ['github.com://owner/repo'];
        yield 'user with a slash' => ['us/er@github.com:owner/repo.git'];
        yield 'user with a space' => ['us er@github.com:owner/repo.git'];
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectsUnknownOrSuspiciousUrls(string $url): void
    {
        self::assertNull((new RemoteUrlParser())->parse($url));
    }

    public function testNeverKeepsCredentials(): void
    {
        $repository = (new RemoteUrlParser())->parse('https://octocat:s3cr3t-token@github.com/owner/repo.git');

        self::assertNotNull($repository);
        self::assertStringNotContainsString('s3cr3t', $repository->webUrl);
        self::assertStringNotContainsString('octocat', $repository->webUrl);
        self::assertStringNotContainsString('@', $repository->webUrl);
    }
}
