<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Cache;

use JulienBohy\GitProfilerBundle\Git\Cache\GitCacheKeys;
use JulienBohy\GitProfilerBundle\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class GitCacheKeysTest extends TestCase
{
    use TemporaryDirectory;

    private const OID = 'a94a8fe5ccb19ba61c4c0873d391e987982fbbd3';
    private const OTHER_OID = 'b94a8fe5ccb19ba61c4c0873d391e987982fbbd3';

    private string $root;
    private string $common;
    private GitCacheKeys $keys;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('gpb_keys_');
        $this->common = $this->root . '/.git';
        mkdir($this->common . '/refs/tags', recursive: true);
        file_put_contents($this->common . '/config', "[core]\n");
        $this->keys = new GitCacheKeys();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testKeysAreValidPsr6KeysAndStable(): void
    {
        $key = $this->keys->history(['log', '@{u}..HEAD'], $this->common, self::OID, self::OTHER_OID);

        self::assertMatchesRegularExpression('/^gpb_[0-9a-f]{64}$/', $key);
        self::assertSame($key, $this->keys->history(['log', '@{u}..HEAD'], $this->common, self::OID, self::OTHER_OID));
    }

    public function testHistoryDependsOnHeadUpstreamAndArguments(): void
    {
        $key = $this->keys->history(['log', '-n', '30'], $this->common, self::OID, self::OID);

        self::assertNotSame($key, $this->keys->history(['log', '-n', '30'], $this->common, self::OTHER_OID, self::OID));
        self::assertNotSame($key, $this->keys->history(['log', '-n', '30'], $this->common, self::OID, self::OTHER_OID));
        self::assertNotSame($key, $this->keys->history(['log', '-n', '30'], $this->common, self::OID, null));
        self::assertNotSame($key, $this->keys->history(['log', '-n', '5'], $this->common, self::OID, self::OID));
    }

    public function testHistoryDependsOnTheShallowBoundary(): void
    {
        $key = $this->keys->history(['log'], $this->common, self::OID, null);

        file_put_contents($this->common . '/shallow', self::OID . "\n");

        self::assertNotSame($key, $this->keys->history(['log'], $this->common, self::OID, null));
    }

    public function testDescribeDependsOnHead(): void
    {
        self::assertNotSame(
            $this->keys->describe(['describe'], $this->common, self::OID),
            $this->keys->describe(['describe'], $this->common, self::OTHER_OID),
        );
    }

    public function testDescribeSeesALooseTagAtTheTopOfRefsTags(): void
    {
        $key = $this->keys->describe(['describe'], $this->common, self::OID);

        file_put_contents($this->common . '/refs/tags/v1', self::OID . "\n");

        self::assertNotSame($key, $this->keys->describe(['describe'], $this->common, self::OID));
    }

    public function testDescribeSeesANestedLooseTagEvenWhenTheTopDirectoryDidNotChange(): void
    {
        mkdir($this->common . '/refs/tags/release');
        file_put_contents($this->common . '/refs/tags/release/v1', self::OID . "\n");
        $topMtime = (int) filemtime($this->common . '/refs/tags');
        $key = $this->keys->describe(['describe'], $this->common, self::OID);

        file_put_contents($this->common . '/refs/tags/release/v2', self::OID . "\n");
        touch($this->common . '/refs/tags', $topMtime);

        self::assertSame($topMtime, (int) filemtime($this->common . '/refs/tags'));
        self::assertNotSame($key, $this->keys->describe(['describe'], $this->common, self::OID));
    }

    public function testDescribeSeesATagMovedToAnotherCommit(): void
    {
        file_put_contents($this->common . '/refs/tags/v1', self::OID . "\n");
        touch($this->common . '/refs/tags/v1', 1_700_000_000);
        $key = $this->keys->describe(['describe'], $this->common, self::OID);

        file_put_contents($this->common . '/refs/tags/v1', self::OTHER_OID . "\n"); // same size
        touch($this->common . '/refs/tags/v1', 1_700_000_100);

        self::assertNotSame($key, $this->keys->describe(['describe'], $this->common, self::OID));
    }

    public function testDescribeSeesPackedRefsChange(): void
    {
        $key = $this->keys->describe(['describe'], $this->common, self::OID);

        file_put_contents($this->common . '/packed-refs', self::OID . " refs/tags/v1\n");

        self::assertNotSame($key, $this->keys->describe(['describe'], $this->common, self::OID));
    }

    public function testDescribeSeesAModifiedPackedRefsWithTheSameSize(): void
    {
        file_put_contents($this->common . '/packed-refs', self::OID . " refs/tags/v1\n");
        touch($this->common . '/packed-refs', 1_700_000_000);
        $key = $this->keys->describe(['describe'], $this->common, self::OID);

        file_put_contents($this->common . '/packed-refs', self::OTHER_OID . " refs/tags/v1\n");
        touch($this->common . '/packed-refs', 1_700_000_100);

        self::assertNotSame($key, $this->keys->describe(['describe'], $this->common, self::OID));
    }

    public function testDescribeIsNeverCachedWithTheReftableBackend(): void
    {
        mkdir($this->common . '/reftable');

        self::assertNull($this->keys->describe(['describe'], $this->common, self::OID));
    }

    public function testConfigDependsOnTheRepositoryConfigFile(): void
    {
        $key = $this->keys->config(['config', '--list'], $this->common);
        self::assertSame($key, $this->keys->config(['config', '--list'], $this->common));

        touch($this->common . '/config', 1_700_000_000);
        self::assertNotSame($key, $this->keys->config(['config', '--list'], $this->common));
    }

    public function testConfigDependsOnTheSizeOfTheConfigFile(): void
    {
        touch($this->common . '/config', 1_700_000_000);
        $key = $this->keys->config(['config', '--list'], $this->common);

        file_put_contents($this->common . '/config', "[core]\n\tbare = false\n");
        touch($this->common . '/config', 1_700_000_000);

        self::assertNotSame($key, $this->keys->config(['config', '--list'], $this->common));
    }

    public function testConfigDependsOnTheGlobalConfigFile(): void
    {
        $home = $this->root . '/home';
        mkdir($home);
        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $key = $this->keys->config(['config', '--list'], $this->common);
            file_put_contents($home . '/.gitconfig', "[user]\n\tname = x\n");
            $changed = $this->keys->config(['config', '--list'], $this->common);
        } finally {
            false === $previous ? putenv('HOME') : putenv('HOME=' . $previous);
        }

        self::assertNotSame($key, $changed);
    }

    public function testGitDirectoryKeyIgnoresTheModificationTimeOfAGitDirectory(): void
    {
        $key = $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root);

        self::assertNotNull($key);
        self::assertSame($key, $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root));

        // Lock files come and go in `.git` all the time (and with every `git status` before git 2.15).
        touch($this->root . '/.git', 1_700_000_000);
        file_put_contents($this->root . '/.git/index.lock', '');
        unlink($this->root . '/.git/index.lock');
        self::assertSame($key, $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root));
    }

    public function testGitDirectoryKeyChangesWhenTheDirectoryStopsLookingLikeARepository(): void
    {
        mkdir($this->common . '/objects');
        file_put_contents($this->common . '/HEAD', "ref: refs/heads/main\n");
        $key = $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root);

        unlink($this->common . '/HEAD');

        self::assertNotSame($key, $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root));
    }

    public function testGitDirectoryKeyChangesWhenTheGitDirectoryIsReplaced(): void
    {
        $key = $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root);
        // Created while the old one still exists, so it cannot get the same inode.
        $other = $this->root . '/.other';
        mkdir($other . '/refs/tags', recursive: true);
        $this->removeDirectory($this->common);
        rename($other, $this->common);

        $replaced = $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root);

        self::assertNotNull($replaced);
        self::assertNotSame($key, $replaced);
    }

    public function testGitDirectoryKeyOfAGitFileFollowsItsContent(): void
    {
        $this->removeDirectory($this->common);
        file_put_contents($this->root . '/.git', "gitdir: /one\n");
        touch($this->root . '/.git', 1_700_000_000);
        $key = $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root);
        self::assertNotNull($key);
        self::assertSame($key, $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root));

        file_put_contents($this->root . '/.git', "gitdir: /two\n"); // same size
        touch($this->root . '/.git', 1_700_000_100);

        self::assertNotSame($key, $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root));
    }

    public function testGitDirectoryKeyChangesWhenADirectoryReplacesTheGitFile(): void
    {
        $this->removeDirectory($this->common);
        file_put_contents($this->root . '/.git', "gitdir: /one\n");
        $key = $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root);

        unlink($this->root . '/.git');
        mkdir($this->root . '/.git');

        self::assertNotSame($key, $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root));
    }

    public function testGitDirectoryDependsOnTheProjectDirectory(): void
    {
        mkdir($this->root . '/app');

        self::assertNotSame(
            $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root),
            $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root . '/app'),
        );
    }

    public function testGitDirectoryLooksForTheGitEntryInTheParentsOfTheProject(): void
    {
        mkdir($this->root . '/app/deep', recursive: true);

        $key = $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root . '/app/deep');
        self::assertNotNull($key);

        // A repository closer to the project takes over.
        mkdir($this->root . '/app/.git');
        self::assertNotSame($key, $this->keys->gitDirectory(['rev-parse', '--git-dir'], $this->root . '/app/deep'));
    }

    public function testGitDirectoryIsNotCachedWithoutAnyGitEntry(): void
    {
        $this->removeDirectory($this->root . '/.git');
        $empty = $this->createTemporaryDirectory('gpb_keys_empty_');

        try {
            // Neither the directory nor its parents (the system temporary directory is not a repository).
            self::assertNull($this->keys->gitDirectory(['rev-parse', '--git-dir'], $empty));
        } finally {
            $this->removeDirectory($empty);
        }
    }
}
