<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Parser;

use JulienBohy\GitProfilerBundle\Git\Parser\GitConfigParser;
use PHPUnit\Framework\TestCase;

final class GitConfigParserTest extends TestCase
{
    public function testUsesTheRemoteOfTheBranch(): void
    {
        $remote = (new GitConfigParser())->remote($this->config([
            'user.name=Ada',
            'remote.origin.url=git@github.com:o/origin.git',
            'remote.fork.url=git@github.com:me/fork.git',
            'branch.main.remote=origin',
            'branch.feature.remote=fork',
        ]), 'feature');

        self::assertNotNull($remote);
        self::assertSame('fork', $remote->name);
        self::assertSame('git@github.com:me/fork.git', $remote->url);
    }

    public function testFallsBackToOriginWhenTheBranchHasNoRemote(): void
    {
        $remote = (new GitConfigParser())->remote($this->config([
            'remote.upstream.url=git@github.com:o/up.git',
            'remote.origin.url=git@github.com:o/origin.git',
            'branch.main.remote=upstream',
        ]), 'other');

        self::assertNotNull($remote);
        self::assertSame('origin', $remote->name);
        self::assertSame('git@github.com:o/origin.git', $remote->url);
    }

    public function testNoRemoteWithoutOriginNorBranchRemote(): void
    {
        self::assertNull((new GitConfigParser())->remote($this->config(['remote.upstream.url=git@github.com:o/up.git']), 'main'));
        self::assertNull((new GitConfigParser())->remote('', 'main'));
    }

    public function testNoRemoteWhenTheRemoteOfTheBranchHasNoUrl(): void
    {
        // The remote of the branch wins even if origin exists: it is the upstream's remote.
        self::assertNull((new GitConfigParser())->remote($this->config([
            'remote.origin.url=git@github.com:o/origin.git',
            'branch.main.remote=.',
        ]), 'main'));
    }

    public function testBranchAndRemoteNamesMayContainDotsAndSlashes(): void
    {
        $remote = (new GitConfigParser())->remote($this->config([
            'remote.my.fork/x.url=git@github.com:me/fork.git',
            'branch.release/1.2.remote=my.fork/x',
        ]), 'release/1.2');

        self::assertNotNull($remote);
        self::assertSame('my.fork/x', $remote->name);
    }

    public function testBranchNamedLikeAConfigSuffix(): void
    {
        $remote = (new GitConfigParser())->remote($this->config([
            'remote.origin.url=git@github.com:o/origin.git',
            'remote.up.url=git@github.com:o/up.git',
            'branch.a.remote.remote=up',
        ]), 'a.remote');

        self::assertNotNull($remote);
        self::assertSame('up', $remote->name);
    }

    public function testTheFirstUrlOfARemoteIsUsed(): void
    {
        $remote = (new GitConfigParser())->remote($this->config([
            'remote.origin.url=git@github.com:o/first.git',
            'remote.origin.url=git@github.com:o/second.git',
        ]), 'main');

        self::assertSame('git@github.com:o/first.git', $remote?->url);
    }

    public function testTheLastRemoteOfABranchWins(): void
    {
        $remote = (new GitConfigParser())->remote($this->config([
            'remote.a.url=git@github.com:o/a.git',
            'remote.b.url=git@github.com:o/b.git',
            'branch.main.remote=a',
            'branch.main.remote=b',
        ]), 'main');

        self::assertSame('b', $remote?->name);
    }

    public function testOnlyTheUrlAndRemoteKeysAreRead(): void
    {
        self::assertNull((new GitConfigParser())->remote($this->config([
            'remote.origin.pushurl=git@github.com:o/push.git',
            'remote.origin.urlx=git@github.com:o/x.git',
            'remote.origin.fetch=+refs/heads/*:refs/remotes/origin/*',
            'branch.main.pushremote=origin',
            'xbranch.main.remote=origin',
            'xremote.origin.url=git@github.com:o/x.git',
            'remote..url=git@github.com:o/x.git',
        ]), 'main'));
    }

    public function testOnlyExactBranchRemoteKeysSelectTheRemote(): void
    {
        // Keys that merely contain "branch.<name>.remote" must not redirect to another remote.
        $remote = (new GitConfigParser())->remote($this->config([
            'remote.origin.url=git@github.com:o/origin.git',
            'remote.fork.url=git@github.com:me/fork.git',
            'xbranch.main.remote=fork',
            'branch.main.remotex=fork',
        ]), 'main');

        self::assertNotNull($remote);
        self::assertSame('origin', $remote->name);
    }

    public function testAValueIsKeptWholeEvenWithSeveralNewlines(): void
    {
        $remote = (new GitConfigParser())->remote("remote.origin.url\nfirst\nsecond\nthird\0", 'main');

        self::assertSame("first\nsecond\nthird", $remote?->url);
    }

    public function testValuesMayContainNewlinesAndKeysMayHaveNoValue(): void
    {
        $output = "core.bare\0alias.x\nline1\nline2\0remote.origin.url\ngit@github.com:o/r.git\0";

        self::assertSame('git@github.com:o/r.git', (new GitConfigParser())->remote($output, 'main')?->url);
    }

    public function testAKeyWithoutValueGivesAnEmptyUrl(): void
    {
        $remote = (new GitConfigParser())->remote("remote.origin.url\0", 'main');

        self::assertNotNull($remote);
        self::assertSame('', $remote->url);
    }

    /**
     * @param list<string> $entries "key=value"
     */
    private function config(array $entries): string
    {
        return implode('', array_map(static fn (string $entry): string => str_replace('=', "\n", $entry) . "\0", $entries));
    }
}
