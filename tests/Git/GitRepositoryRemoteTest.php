<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git;

use JulienBohy\GitProfilerBundle\DataCollector\GitDataCollector;
use JulienBohy\GitProfilerBundle\Git\Command\ProcessGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\GitDirectory;
use JulienBohy\GitProfilerBundle\Git\GitInfo;
use JulienBohy\GitProfilerBundle\Git\GitRepository;
use JulienBohy\GitProfilerBundle\Git\OperationDetector;
use JulienBohy\GitProfilerBundle\Git\Remote\GitProvider;
use JulienBohy\GitProfilerBundle\Git\StashCounter;
use JulienBohy\GitProfilerBundle\Git\TagPresence;
use JulienBohy\GitProfilerBundle\Tests\Support\GitRepositoryInitializer;
use JulienBohy\GitProfilerBundle\Tests\Support\RecordingLogger;
use JulienBohy\GitProfilerBundle\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

/**
 * Links to the remote, version and stashes on real repositories. Only commands available since git 2.11.
 */
final class GitRepositoryRemoteTest extends TestCase
{
    use GitRepositoryInitializer;
    use TemporaryDirectory;

    private const URL = 'https://octocat:s3cr3t-token@github.com/owner/repo.git';

    private string $dir;
    private string $remoteDir;
    private string $otherDir;
    private TraceableGitCommandRunner $tracer;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->dir = $this->createTemporaryDirectory('gpb_rl_');
        $this->remoteDir = $this->createTemporaryDirectory('gpb_rl_remote_');
        $this->otherDir = $this->createTemporaryDirectory('gpb_rl_wt_');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
        $this->removeDirectory($this->remoteDir);
        $this->removeDirectory($this->otherDir);
    }

    public function testNoRemoteWithoutAnyRemoteConfigured(): void
    {
        $this->initRepository();

        $info = $this->read($this->dir);

        self::assertNull($info->remote);
        self::assertNull($info->upstreamBranch);
        self::assertSame(self::oid($this->dir), $info->headHash);
    }

    public function testWithoutUpstreamTheRemoteFallsBackToOrigin(): void
    {
        $this->initRepository();
        $this->git('remote', 'add', 'origin', 'git@github.com:owner/repo.git');

        $info = $this->read($this->dir);

        self::assertFalse($info->hasUpstream);
        self::assertNotNull($info->remote);
        self::assertSame(GitProvider::GitHub, $info->remote->provider);
        self::assertSame('https://github.com/owner/repo', $info->remote->webUrl);
        self::assertNull($info->upstreamBranch);
        // Nothing is known to be on the remote: no commit is linked.
        self::assertSame([], array_filter($this->collect($info)->getGraphRows(), static fn (array $row): bool => ($row['url'] ?? null) !== null));
    }

    public function testWithoutUpstreamAnotherRemoteIsNotGuessed(): void
    {
        $this->initRepository();
        $this->git('remote', 'add', 'upstream', 'git@github.com:owner/repo.git');

        self::assertNull($this->read($this->dir)->remote);
    }

    public function testAnUnrecognisedHostGivesNoLinks(): void
    {
        $this->initRepository();
        $this->pushToRemote('https://git.example.org/owner/repo.git');

        $info = $this->read($this->dir);
        $collector = $this->collect($info);

        self::assertTrue($info->hasUpstream);
        self::assertNull($info->remote);
        self::assertNull($collector->getRemote());
        self::assertNull($collector->getUpstreamUrl());
        self::assertNull($collector->getCompareUrl());
        foreach ($collector->getGraphRows() as $row) {
            self::assertNull($row['url'] ?? null);
        }
    }

    public function testLocalRemotePathsGiveNoLinks(): void
    {
        $this->initRepository();
        $this->git('remote', 'add', 'origin', $this->remoteDir);

        self::assertNull($this->read($this->dir)->remote);
    }

    public function testOnlyPushedCommitsAreLinked(): void
    {
        $this->initRepository();
        $this->pushToRemote(self::URL);
        $pushed = self::oid($this->dir);
        $this->commit('local only');
        $local = self::oid($this->dir);

        $collector = $this->collect($this->read($this->dir));

        self::assertSame(['provider' => 'GitHub', 'url' => 'https://github.com/owner/repo'], $collector->getRemote());
        self::assertSame('https://github.com/owner/repo/tree/main', $collector->getUpstreamUrl());

        $rows = $collector->getGraphRows();
        self::assertCount(2, $rows);
        self::assertTrue($rows[0]['isHead']);
        self::assertFalse($rows[0]['isPushed']);
        self::assertNull($rows[0]['url'] ?? null);
        self::assertTrue($rows[1]['isPushed']);
        self::assertSame('https://github.com/owner/repo/commit/' . $pushed, $rows[1]['url'] ?? null);
        self::assertStringNotContainsString($local, (string) json_encode($rows));
        self::assertNotSame($pushed, $local);
        // Ahead: nothing to compare.
        self::assertNull($collector->getCompareUrl());
    }

    public function testNoCredentialIsCollected(): void
    {
        $this->initRepository();
        $this->pushToRemote(self::URL);
        $this->commit('local only');

        $collector = $this->collect($this->read($this->dir));
        $collected = (string) json_encode([
            $collector->getRemote(), $collector->getUpstreamUrl(), $collector->getCompareUrl(), $collector->getGraphRows(), $collector->getCommands(),
        ], \JSON_UNESCAPED_SLASHES);

        self::assertStringContainsString('github.com/owner/repo', $collected);
        self::assertStringNotContainsString('s3cr3t', $collected);
        self::assertStringNotContainsString('octocat', $collected);
    }

    public function testNestedBranchNamesAreLinkedWithTheirSlashes(): void
    {
        $this->initRepository();
        $this->git('remote', 'add', 'origin', $this->remoteDir);
        $this->initGitRepository($this->remoteDir, bare: true);
        $this->git('checkout', '-b', 'feature/my#branch');
        $this->git('push', '-u', 'origin', 'feature/my#branch');
        $this->git('remote', 'set-url', 'origin', 'git@gitlab.com:group/sub/repo.git');

        $info = $this->read($this->dir);

        self::assertSame('feature/my#branch', $info->upstreamBranch);
        self::assertSame('https://gitlab.com/group/sub/repo/-/tree/feature/my%23branch', $this->collect($info)->getUpstreamUrl());
    }

    public function testTheRemoteOfTheUpstreamIsUsedRatherThanOrigin(): void
    {
        $this->initRepository();
        $this->initGitRepository($this->remoteDir, bare: true);
        $this->git('remote', 'add', 'origin', 'git@github.com:owner/origin.git');
        $this->git('remote', 'add', 'fork', $this->remoteDir);
        $this->git('push', '-u', 'fork', 'main');
        $this->git('remote', 'set-url', 'fork', 'git@bitbucket.org:me/fork.git');

        $info = $this->read($this->dir);

        self::assertSame('fork/main', $info->upstreamRef);
        self::assertSame(GitProvider::Bitbucket, $info->remote?->provider);
        self::assertSame('https://bitbucket.org/me/fork/branch/main', $this->collect($info)->getUpstreamUrl());
    }

    public function testBehindWithoutBeingAheadGivesACompareLink(): void
    {
        $this->initRepository();
        $this->pushToRemote(self::URL);
        $this->commit('remote work');
        $this->git('push', 'origin', 'main');
        $this->git('reset', '--hard', '--quiet', 'HEAD~1');
        $head = self::oid($this->dir);

        $collector = $this->collect($this->read($this->dir));

        self::assertSame(1, $collector->getBehindCommitsCount());
        self::assertSame(0, $collector->getUnpushedCommitsCount());
        self::assertSame('https://github.com/owner/repo/compare/' . $head . '...main', $collector->getCompareUrl());
    }

    public function testNoCompareLinkWhenDiverged(): void
    {
        $this->initRepository();
        $this->pushToRemote(self::URL);
        $this->commit('remote work');
        $this->git('push', 'origin', 'main');
        $this->git('reset', '--hard', '--quiet', 'HEAD~1');
        $this->commit('local work');

        $collector = $this->collect($this->read($this->dir));

        self::assertSame(1, $collector->getBehindCommitsCount());
        self::assertSame(1, $collector->getUnpushedCommitsCount());
        self::assertNull($collector->getCompareUrl());
        self::assertSame('https://github.com/owner/repo/tree/main', $collector->getUpstreamUrl());
    }

    public function testNoCompareLinkWhenInSyncOrOnlyAhead(): void
    {
        $this->initRepository();
        $this->pushToRemote(self::URL);

        self::assertNull($this->collect($this->read($this->dir))->getCompareUrl());

        $this->commit('local work');

        self::assertNull($this->collect($this->read($this->dir))->getCompareUrl());
    }

    public function testNoCompareLinkOnBitbucket(): void
    {
        $this->initRepository();
        $this->pushToRemote('git@bitbucket.org:me/repo.git');
        $this->commit('remote work');
        $this->git('push', 'origin', 'main');
        $this->git('reset', '--hard', '--quiet', 'HEAD~1');

        $collector = $this->collect($this->read($this->dir));

        self::assertSame(1, $collector->getBehindCommitsCount());
        self::assertNull($collector->getCompareUrl());
        self::assertSame('https://bitbucket.org/me/repo/branch/main', $collector->getUpstreamUrl());
    }

    public function testTheRemoteIsReadInASingleProcess(): void
    {
        $this->initRepository();
        $this->pushToRemote(self::URL);

        $this->read($this->dir);

        $configCommands = array_filter($this->tracer->getTraces(), static fn ($trace): bool => str_starts_with($trace->commandLine(), 'git config'));
        self::assertCount(1, $configCommands);
        self::assertCount(0, array_filter($this->tracer->getTraces(), static fn ($trace): bool => str_contains($trace->commandLine(), 'stash')));
    }

    public function testNoVersionWithoutTag(): void
    {
        $this->initRepository();

        $info = $this->read($this->dir);

        self::assertNull($info->describe);
        self::assertSame('main', $info->branch);
        // Certain to fail: git describe is not even run, so no failed command and nothing to log.
        self::assertSame([], $this->describeCommands());
        self::assertSame([], $this->logger->records);
    }

    public function testDescribeRunsWithALooseTag(): void
    {
        $this->initRepository();
        $this->git('tag', 'v1/rc.1'); // slash in the name: nested loose file

        self::assertSame('v1/rc.1', $this->read($this->dir)->describe);
        self::assertCount(1, $this->describeCommands());
    }

    public function testDescribeRunsWithAPackedTagOnly(): void
    {
        $this->initRepository();
        $this->git('tag', '-a', '0.4.0', '-m', 'release');
        $this->git('pack-refs', '--all');
        self::assertFalse(is_dir($this->dir . '/.git/refs/tags/0.4.0'));
        self::assertFileDoesNotExist($this->dir . '/.git/refs/tags/0.4.0');

        self::assertSame('0.4.0', $this->read($this->dir)->describe);
        self::assertCount(1, $this->describeCommands());
    }

    public function testDescribeIsSkippedWhenPackedRefsHoldNoTag(): void
    {
        // packed-refs exists but holds no tag: still certain there is none.
        $this->initRepository();
        $this->git('pack-refs', '--all');
        self::assertFileExists($this->dir . '/.git/packed-refs');

        self::assertNull($this->read($this->dir)->describe);
        self::assertSame([], $this->describeCommands());
    }

    public function testDescribeRunsWithTagsInTheCommonDirectoryOfAWorktree(): void
    {
        $this->initRepository();
        $this->git('tag', '1.0.0');
        $worktree = $this->otherDir . '/wt';
        $this->git('worktree', 'add', $worktree, '-b', 'feature');

        self::assertSame('1.0.0', $this->read($worktree)->describe);
        self::assertCount(1, $this->describeCommands());
    }

    public function testDescribeRunsWithTheReftableDirectory(): void
    {
        $this->initRepository();
        mkdir($this->dir . '/.git/reftable');

        $this->read($this->dir);

        self::assertCount(1, $this->describeCommands());
    }

    public function testDescribeRunsWhenATagIsNotReachableAndIsOnlyLogged(): void
    {
        $this->initRepository();
        $this->git('checkout', '--quiet', '-b', 'other');
        $this->commit('other');
        $this->git('tag', '0.9.0');
        $this->git('checkout', '--quiet', 'main');

        self::assertNull($this->read($this->dir)->describe);
        self::assertCount(1, $this->describeCommands());
        self::assertSame('debug', $this->logger->records[0]['level']);
        self::assertSame('version', $this->logger->records[0]['context']['section'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function describeCommands(): array
    {
        return array_values(array_filter(
            array_map(static fn ($trace): string => $trace->commandLine(), $this->tracer->getTraces()),
            static fn (string $command): bool => str_starts_with($command, 'git describe'),
        ));
    }

    public function testVersionOfATaggedCommit(): void
    {
        $this->initRepository();
        $this->git('tag', '-a', '0.4.0', '-m', 'release');

        self::assertSame('0.4.0', $this->read($this->dir)->describe);
        self::assertSame([], $this->logger->records);
    }

    public function testVersionAfterTheTag(): void
    {
        $this->initRepository();
        $this->git('tag', '0.4.0'); // lightweight tags count too
        $this->commit('one');
        $this->commit('two');
        $this->commit('three');

        $info = $this->read($this->dir);

        self::assertNotNull($info->headHash);
        self::assertSame('0.4.0-3-g' . substr($info->headHash, 0, 7), $info->describe);
        self::assertSame('0.4.0-3-g' . substr($info->headHash, 0, 7), $this->collect($info)->getDescribe());
    }

    public function testVersionIsNullWhenTheTagIsNotReachable(): void
    {
        $this->initRepository();
        $this->git('checkout', '--quiet', '-b', 'other');
        $this->commit('other');
        $this->git('tag', '0.9.0');
        $this->git('checkout', '--quiet', 'main');

        self::assertNull($this->read($this->dir)->describe);
    }

    public function testStashCount(): void
    {
        $this->initRepository();
        self::assertSame(0, $this->read($this->dir)->stashCount);

        $this->stash('one');
        self::assertSame(1, $this->read($this->dir)->stashCount);

        $this->stash('two');
        $info = $this->read($this->dir);
        self::assertSame(2, $info->stashCount);
        self::assertSame(2, $this->collect($info)->getStashCount());
        self::assertSame([], $this->logger->records);

        $this->git('stash', 'drop');
        self::assertSame(1, $this->read($this->dir)->stashCount);

        $this->git('stash', 'drop');
        self::assertSame(0, $this->read($this->dir)->stashCount);
    }

    public function testStashesAreSharedWithLinkedWorktrees(): void
    {
        $this->initRepository();
        $this->stash('main one');
        $this->stash('main two');
        $worktree = $this->otherDir . '/wt';
        $this->git('worktree', 'add', '-b', 'feature', $worktree);

        self::assertSame(2, $this->read($worktree)->stashCount);

        // A stash made in the worktree is visible from the main working tree.
        file_put_contents($worktree . '/file.txt', "worktree change\n");
        (new Process(['git', 'stash'], $worktree))->mustRun();

        self::assertSame(3, $this->read($worktree)->stashCount);
        self::assertSame(3, $this->read($this->dir)->stashCount);
    }

    public function testRemoteAndVersionWorkInALinkedWorktree(): void
    {
        $this->initRepository();
        $this->pushToRemote(self::URL);
        $this->git('tag', '1.0.0');
        $worktree = $this->otherDir . '/wt';
        $this->git('worktree', 'add', '-b', 'feature', $worktree);
        $this->commit('feature work', $worktree);

        $info = $this->read($worktree);

        self::assertSame('https://github.com/owner/repo', $info->remote?->webUrl);
        self::assertNull($info->upstreamBranch);
        self::assertSame('1.0.0-1-g' . substr((string) $info->headHash, 0, 7), $info->describe);
    }

    public function testWithoutStashCounterThereAreNoStashes(): void
    {
        $this->initRepository();
        $this->stash('one');

        $info = (new GitRepository(new ProcessGitCommandRunner($this->dir)))->read();

        self::assertNotNull($info);
        self::assertSame(0, $info->stashCount);
    }

    private function read(string $directory): GitInfo
    {
        $this->tracer = new TraceableGitCommandRunner(new ProcessGitCommandRunner($directory));
        $this->logger = new RecordingLogger();
        $gitDirectory = new GitDirectory($this->tracer, $directory);

        $info = (new GitRepository(
            $this->tracer,
            logger: $this->logger,
            operationDetector: new OperationDetector($this->tracer, $directory, $this->logger, $gitDirectory),
            stashCounter: new StashCounter($gitDirectory),
            tagPresence: new TagPresence($gitDirectory),
        ))->read();
        self::assertNotNull($info);

        return $info;
    }

    private function collect(GitInfo $info): GitDataCollector
    {
        $repository = $this->createStub(\JulienBohy\GitProfilerBundle\Git\GitRepositoryInterface::class);
        $repository->method('read')->willReturn($info);

        $collector = new GitDataCollector($repository);
        $collector->collect(new Request(), new Response());
        $collector->lateCollect();

        return $collector;
    }

    private function initRepository(): void
    {
        $this->initGitRepository($this->dir);
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'Test');
        file_put_contents($this->dir . '/file.txt', "hello\n");
        $this->git('add', '.');
        $this->git('commit', '-m', 'init');
    }

    /**
     * Pushes to a bare repository, while the (fetch) URL of the remote is one that does not exist: pushes keep going
     * to the bare repository, and update the remote-tracking refs.
     */
    private function pushToRemote(string $url): void
    {
        $this->initGitRepository($this->remoteDir, bare: true);
        $this->git('remote', 'add', 'origin', $this->remoteDir);
        $this->git('push', '-u', 'origin', 'main');
        $this->git('remote', 'set-url', '--push', 'origin', $this->remoteDir);
        $this->git('remote', 'set-url', 'origin', $url);
    }

    private function commit(string $message, ?string $directory = null): void
    {
        $directory ??= $this->dir;
        file_put_contents($directory . '/' . md5($message) . '.txt', $message . "\n");
        (new Process(['git', 'add', '.'], $directory))->mustRun();
        (new Process(['git', 'commit', '-m', $message], $directory))->mustRun();
    }

    private function stash(string $message): void
    {
        file_put_contents($this->dir . '/file.txt', $message . "\n");
        $this->git('stash');
    }

    private static function oid(string $directory): string
    {
        return trim((new Process(['git', 'rev-parse', 'HEAD'], $directory))->mustRun()->getOutput());
    }

    private function git(string ...$args): void
    {
        (new Process(['git', ...$args], $this->dir))->mustRun();
    }
}
