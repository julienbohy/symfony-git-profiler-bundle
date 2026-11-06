<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Remote;

use JulienBohy\GitProfilerBundle\Git\Remote\GitProvider;
use JulienBohy\GitProfilerBundle\Git\Remote\RemoteRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RemoteRepositoryTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    /**
     * @return iterable<string, array{GitProvider, string, string, ?string, string}>
     */
    public static function providers(): iterable
    {
        yield 'github' => [
            GitProvider::GitHub,
            'https://github.com/o/r/commit/' . self::SHA,
            'https://github.com/o/r/tree/feature/x',
            'https://github.com/o/r/compare/' . self::SHA . '...feature/x',
            'GitHub',
        ];
        yield 'gitlab' => [
            GitProvider::GitLab,
            'https://github.com/o/r/-/commit/' . self::SHA,
            'https://github.com/o/r/-/tree/feature/x',
            'https://github.com/o/r/-/compare/' . self::SHA . '...feature/x',
            'GitLab',
        ];
        yield 'bitbucket' => [
            GitProvider::Bitbucket,
            'https://github.com/o/r/commits/' . self::SHA,
            'https://github.com/o/r/branch/feature/x',
            null,
            'Bitbucket',
        ];
    }

    #[DataProvider('providers')]
    public function testBuildsTheUrlsOfEachProvider(GitProvider $provider, string $commit, string $branch, ?string $compare, string $label): void
    {
        $repository = new RemoteRepository($provider, 'https://github.com/o/r');

        self::assertSame($commit, $repository->commitUrl(self::SHA));
        self::assertSame($branch, $repository->branchUrl('feature/x'));
        self::assertSame($compare, $repository->compareUrl(self::SHA, 'feature/x'));
        self::assertSame($label, $provider->label());
    }

    public function testEncodesEachSegmentOfTheBranchAndKeepsTheSlashes(): void
    {
        $repository = new RemoteRepository(GitProvider::GitHub, 'https://github.com/o/r');

        self::assertSame('https://github.com/o/r/tree/feat/a%20b%23c/d%3Fe%25f', $repository->branchUrl('feat/a b#c/d?e%f'));
        self::assertSame('https://github.com/o/r/compare/' . self::SHA . '...feat/a%20b', $repository->compareUrl(self::SHA, 'feat/a b'));
    }

    public function testDetectsTheProviderFromTheHost(): void
    {
        self::assertSame(GitProvider::GitHub, GitProvider::fromHost('github.com'));
        self::assertSame(GitProvider::Bitbucket, GitProvider::fromHost('bitbucket.org'));
        self::assertSame(GitProvider::GitLab, GitProvider::fromHost('gitlab.com'));
        self::assertSame(GitProvider::GitLab, GitProvider::fromHost('gitlab.example.com'));
        self::assertNull(GitProvider::fromHost('example.com'));
        self::assertNull(GitProvider::fromHost('git.github.com.example.org'));
        self::assertNull(GitProvider::fromHost(''));
    }
}
