<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git;

use JulienBohy\GitProfilerBundle\Git\Cache\GitOutputCache;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Command\ProcessGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\GitDirectory;
use JulienBohy\GitProfilerBundle\Git\GitRepository;
use JulienBohy\GitProfilerBundle\Git\TagPresence;
use JulienBohy\GitProfilerBundle\Tests\Support\GitRepositoryInitializer;
use JulienBohy\GitProfilerBundle\Tests\Support\RecordingGitRunner;
use JulienBohy\GitProfilerBundle\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Process\Process;

/**
 * Which git commands start together: status alone, then one batch, then (only when the cache keys need the
 * result of the first batch) a second one. Sequential rounds are what costs time when git hangs.
 */
final class GitRepositoryBatchTest extends TestCase
{
    use GitRepositoryInitializer;
    use TemporaryDirectory;

    private string $dir;
    private string $remoteDir;

    protected function setUp(): void
    {
        $this->dir = $this->createTemporaryDirectory('gpb_batch_');
        $this->remoteDir = $this->createTemporaryDirectory('gpb_batch_remote_');
        $this->initGitRepository($this->dir);
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'Test');
        $this->write('file.txt', "hello\n");
        $this->git('add', '.');
        $this->git('commit', '-m', 'init');
        $this->git('tag', 'v1');

        $this->initGitRepository($this->remoteDir, bare: true);
        $this->git('remote', 'add', 'origin', $this->remoteDir);
        $this->git('push', '-u', 'origin', 'main');

        $this->write('ahead.txt', "ahead\n");
        $this->git('add', 'ahead.txt');
        $this->git('commit', '-m', 'ahead');
        $this->write('staged.txt', "staged\n");
        $this->git('add', 'staged.txt');
        file_put_contents($this->dir . '/file.txt', "unstaged\n");
    }

    /**
     * Dated in the past: git 2.11 to 2.14 ignore GIT_OPTIONAL_LOCKS, and `git status` rewrites the index (so
     * changes the mtime of `.git`) whenever an entry is "racily clean", which would make the keys depend on timing.
     */
    private function write(string $file, string $content): void
    {
        file_put_contents($this->dir . '/' . $file, $content);
        touch($this->dir . '/' . $file, time() - 3600);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
        $this->removeDirectory($this->remoteDir);
    }

    public function testEverythingAfterStatusStartsInOneBatchWithoutCache(): void
    {
        $runner = $this->recorder();

        $info = $this->repository($runner)->read();

        self::assertNotNull($info);
        // `status` alone; everything that does not need the git directory next, all started together...
        self::assertSame([['status']], $runner->sequential);
        self::assertSame(
            [
                ['show', 'diff', 'diff', 'rev-parse', 'config', 'log', 'log', 'diff'],
                // ...and `describe` once the git directory is known (it has to look for tags in it).
                ['describe'],
            ],
            $runner->batches,
        );
        self::assertSame('v1-1-g', substr((string) $info->describe, 0, 6));
        self::assertCount(1, $info->unpushedCommits);
        self::assertCount(2, array_filter($info->workingFiles, static fn ($file): bool => $file->stage->value !== 'committed'));
    }

    public function testTheDiffsAreOnlyStartedWhenTheStatusReportsChanges(): void
    {
        $this->git('reset', '--hard', '--quiet');
        $this->git('clean', '-fdq');
        $runner = $this->recorder();

        $this->repository($runner)->read();

        self::assertSame(['show', 'rev-parse', 'config', 'log', 'log', 'diff'], $runner->batches[0]);
    }

    public function testColdCacheNeedsASecondBatchForTheKeysThatWaitForTheGitDirectoryAndTheUpstream(): void
    {
        $runner = $this->recorder();

        $this->repository($runner, new ArrayAdapter())->read();

        self::assertSame([['status']], $runner->sequential);
        self::assertSame(
            [
                ['show', 'diff', 'diff', 'rev-parse'],
                ['config', 'describe', 'log', 'log', 'diff'],
            ],
            $runner->batches,
        );
    }

    public function testWarmCacheOnlyRunsStatusShowAndTheDiffs(): void
    {
        $pool = new ArrayAdapter();
        $this->repository($this->recorder(), $pool)->read();
        $runner = $this->recorder();

        $info = $this->repository($runner, $pool)->read();

        self::assertNotNull($info);
        self::assertSame([['status']], $runner->sequential);
        self::assertSame([['show', 'diff', 'diff']], $runner->batches);
        self::assertSame('v1-1-g', substr((string) $info->describe, 0, 6));
        self::assertCount(1, $info->unpushedCommits);
    }

    public function testWhenTheGitDirectoryIsCachedTheKeysThatNeedItAreComputedBeforeTheFirstBatch(): void
    {
        $pool = new ArrayAdapter();
        $this->repository($this->recorder(), $pool)->read();
        $this->git('commit', '--allow-empty', '-m', 'moved');
        $runner = $this->recorder();

        $this->repository($runner, $pool)->read();

        self::assertSame([['status']], $runner->sequential);
        self::assertSame(
            [
                // The config is cached (nothing changed in it; the commit also took the staged file along, so one diff
                // is left); the version waits for nothing...
                ['show', 'diff', 'describe'],
                // ...the graph and the unpushed commits need the oid of the upstream, only known after `show`.
                ['log', 'log', 'diff'],
            ],
            $runner->batches,
        );
    }

    public function testWithoutAnUpstreamTheCachedRequestNeedsASingleBatchAfterStatus(): void
    {
        $pool = new ArrayAdapter();
        $this->repository($this->recorder(), $pool)->read();
        $this->git('branch', '--unset-upstream');
        $this->git('commit', '--allow-empty', '-m', 'moved');
        $runner = $this->recorder();

        $this->repository($runner, $pool)->read();

        self::assertSame([['status']], $runner->sequential);
        self::assertSame([['show', 'diff', 'config', 'describe', 'log']], $runner->batches);
    }

    public function testARunnerWithoutTheParallelCapabilityGivesTheSameInformation(): void
    {
        $parallel = $this->repository($this->recorder())->read();

        $plain = new class(new ProcessGitCommandRunner($this->dir)) implements GitCommandRunnerInterface {
            public function __construct(private readonly GitCommandRunnerInterface $inner)
            {
            }

            public function run(array $args): string
            {
                return $this->inner->run($args);
            }
        };
        $gitDirectory = new GitDirectory($plain, $this->dir);
        $sequential = (new GitRepository($plain, tagPresence: new TagPresence($gitDirectory), gitDirectory: $gitDirectory))->read();

        self::assertNotNull($sequential);
        self::assertEquals($parallel, $sequential);
    }

    public function testAFailingCommandOfTheBatchOnlyEmptiesItsOwnSection(): void
    {
        $runner = $this->recorder(failing: '--topo-order');

        $info = $this->repository($runner)->read();

        self::assertNotNull($info);
        self::assertSame([], $info->graphCommits);
        self::assertCount(1, $info->unpushedCommits);
        self::assertNotNull($info->describe);
        self::assertNotEmpty($info->workingFiles);
    }

    public function testAFailingGitDirectoryLookupOnlyLosesTheKeysThatNeedIt(): void
    {
        // `rev-parse --git-dir` fails: no common git directory, so describe and the config are not cached,
        // but every section is still read.
        $pool = new ArrayAdapter();
        $runner = $this->recorder(failing: '--git-dir');

        $info = $this->repository($runner, $pool)->read();

        self::assertNotNull($info);
        self::assertNotEmpty($info->graphCommits);
        self::assertCount(1, $info->unpushedCommits);
        self::assertNotEmpty($info->workingFiles);
        self::assertSame('v1-1-g' . substr($this->gitOutput('rev-parse', 'HEAD'), 0, 7), $info->describe);
    }

    public function testAnUnexpectedShowOutputMakesThePanelUnavailable(): void
    {
        $inner = new ProcessGitCommandRunner($this->dir);
        $runner = new class($inner) implements GitCommandRunnerInterface {
            public function __construct(private readonly GitCommandRunnerInterface $inner)
            {
            }

            public function run(array $args): string
            {
                return $args[0] === 'show' ? "not a hash\n" : $this->inner->run($args);
            }
        };

        self::assertNull((new GitRepository($runner))->read());
    }

    public function testFailedCommandsAreNeverCached(): void
    {
        $pool = new ArrayAdapter();
        $this->repository($this->recorder(failing: '--topo-order'), $pool)->read();

        $info = $this->repository($this->recorder(), $pool)->read();

        self::assertNotNull($info);
        self::assertNotEmpty($info->graphCommits);
    }

    public function testAFailingUnpushedCommandMakesTheBranchLookUnpublished(): void
    {
        $runner = $this->recorder(failing: '@{u}..HEAD');

        $info = $this->repository($runner)->read();

        self::assertNotNull($info);
        self::assertFalse($info->hasUpstream);
        self::assertSame([], $info->unpushedCommits);
        self::assertNotEmpty($info->graphCommits);
    }

    public function testNothingIsCachedWhenHeadMovedBetweenStatusAndShow(): void
    {
        // `status` saw one HEAD, the commands another: the keys computed from `status` would label the wrong history.
        $pool = new ArrayAdapter();
        $before = trim($this->gitOutput('rev-parse', 'HEAD'));
        $runner = $this->recorder(beforeShow: fn () => $this->git('commit', '--allow-empty', '-m', 'raced'));

        $racing = $this->repository($runner, $pool)->read();
        self::assertNotNull($racing);

        $this->git('reset', '--hard', '--quiet', $before);
        $back = $this->repository($this->recorder(), $pool)->read();

        self::assertNotNull($back);
        self::assertNotContains('raced', array_map(static fn ($commit): string => $commit->subject, $back->graphCommits));
    }

    private function recorder(?string $failing = null, ?\Closure $beforeShow = null): RecordingGitRunner
    {
        return new RecordingGitRunner(new ProcessGitCommandRunner($this->dir), $failing, $beforeShow);
    }

    private function repository(RecordingGitRunner $runner, ?ArrayAdapter $pool = null): GitRepository
    {
        $cache = new GitOutputCache($pool);
        $gitDirectory = new GitDirectory($runner, $this->dir, $cache);

        return new GitRepository($runner, tagPresence: new TagPresence($gitDirectory), gitDirectory: $gitDirectory, cache: $cache);
    }

    private function git(string ...$args): void
    {
        (new Process(['git', ...$args], $this->dir))->mustRun();
    }

    private function gitOutput(string ...$args): string
    {
        return (new Process(['git', ...$args], $this->dir))->mustRun()->getOutput();
    }
}
