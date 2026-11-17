<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git;

use JulienBohy\GitProfilerBundle\Git\Cache\GitOutputCache;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandTrace;
use JulienBohy\GitProfilerBundle\Git\Command\ProcessGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\GitDirectory;
use JulienBohy\GitProfilerBundle\Git\GitInfo;
use JulienBohy\GitProfilerBundle\Git\GitRepository;
use JulienBohy\GitProfilerBundle\Git\OperationDetector;
use JulienBohy\GitProfilerBundle\Git\StashCounter;
use JulienBohy\GitProfilerBundle\Git\TagPresence;
use JulienBohy\GitProfilerBundle\Tests\Support\GitRepositoryInitializer;
use JulienBohy\GitProfilerBundle\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Process\Process;

/**
 * The cross-request cache, against real repositories: a hit only when nothing that could change the output
 * changed, whatever the counters say.
 */
final class GitRepositoryCacheTest extends TestCase
{
    use GitRepositoryInitializer;
    use TemporaryDirectory;

    private string $dir;
    private string $remoteDir;
    private ArrayAdapter $pool;

    /**
     * @var list<string>
     */
    private array $clones = [];

    protected function setUp(): void
    {
        $this->dir = $this->createTemporaryDirectory('gpb_cache_');
        $this->remoteDir = $this->createTemporaryDirectory('gpb_cache_remote_');
        $this->pool = new ArrayAdapter();

        $this->initGitRepository($this->dir);
        $this->git($this->dir, 'config', 'user.email', 'test@example.com');
        $this->git($this->dir, 'config', 'user.name', 'Test');
        $this->commit($this->dir, 'init');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
        $this->removeDirectory($this->remoteDir);
        array_map($this->removeDirectory(...), $this->clones);
    }

    public function testASecondReadServesTheHistoryCommandsFromTheCache(): void
    {
        $this->configureUpstream();

        [$first, $firstTraces] = $this->read();
        [$second, $secondTraces] = $this->read();

        self::assertSame([], $this->cachedNames($firstTraces));
        self::assertSame(['config', 'graph', 'rev-parse'], $this->cachedNames($secondTraces));
        // status and show are never cached, and neither is anything else that is not listed above.
        self::assertSame(['show', 'status'], $this->liveNames($secondTraces));
        self::assertEquals($first, $second);
    }

    public function testACachedCommandIsTracedWithoutDuration(): void
    {
        $this->read();
        [, $traces] = $this->read();

        $cached = array_values(array_filter($traces, static fn (GitCommandTrace $trace): bool => $trace->cached));

        self::assertNotEmpty($cached);
        foreach ($cached as $trace) {
            self::assertTrue($trace->successful);
            self::assertSame(0.0, $trace->durationMs);
        }
    }

    public function testWithoutAPoolNothingIsCached(): void
    {
        $this->read();
        [, $traces] = $this->read(pool: false);

        self::assertSame([], $this->cachedNames($traces));
    }

    public function testANewCommitMissesTheGraph(): void
    {
        $this->read();
        $this->commit($this->dir, 'second');

        [$info, $traces] = $this->read();

        self::assertContains('graph', $this->liveNames($traces));
        self::assertSame(['second', 'init'], array_map(static fn ($commit): string => $commit->subject, $info->graphCommits));
    }

    public function testAForcePushedUpstreamMissesEvenWithIdenticalAheadAndBehindCounts(): void
    {
        $this->configureUpstream();
        $this->commit($this->dir, 'local');
        $clone = $this->cloneRemote();
        $this->commit($clone, 'remote one');
        $this->git($clone, 'push', 'origin', 'main');
        $this->git($this->dir, 'fetch', 'origin');

        [$before] = $this->read();
        self::assertSame(['init', 'local', 'remote one'], $this->subjects($before));
        self::assertSame(1, $before->behindCount);

        // The same shape (1 ahead, 1 behind) with another upstream commit.
        $this->git($clone, 'reset', '--hard', 'HEAD~1');
        $this->commit($clone, 'remote two');
        $this->git($clone, 'push', '--force', 'origin', 'main');
        $this->git($this->dir, 'fetch', 'origin');

        [$after, $traces] = $this->read();

        self::assertSame(1, $after->behindCount);
        self::assertCount(1, $after->unpushedCommits);
        self::assertSame(['init', 'local', 'remote two'], $this->subjects($after));
        self::assertContains('graph', $this->liveNames($traces));
        self::assertContains('unpushed-log', $this->liveNames($traces));
        self::assertContains('unpushed-diff', $this->liveNames($traces));
    }

    public function testTheUnpushedCommitsAreCachedWhileHeadAndUpstreamStay(): void
    {
        $this->configureUpstream();
        $this->commit($this->dir, 'ahead');

        [$first] = $this->read();
        [$second, $traces] = $this->read();

        self::assertContains('unpushed-log', $this->cachedNames($traces));
        self::assertContains('unpushed-diff', $this->cachedNames($traces));
        self::assertCount(1, $second->unpushedCommits);
        self::assertEquals($first, $second);
    }

    public function testAnotherGraphLimitMissesTheGraph(): void
    {
        $this->commit($this->dir, 'second');
        $this->commit($this->dir, 'third');

        [$full] = $this->read(limit: 30);
        [$limited, $traces] = $this->read(limit: 1);

        self::assertCount(3, $full->graphCommits);
        self::assertCount(1, $limited->graphCommits);
        self::assertContains('graph', $this->liveNames($traces));
    }

    public function testALooseTagAtHeadMissesDescribe(): void
    {
        $this->git($this->dir, 'tag', 'v1');
        $this->commit($this->dir, 'second');

        [$before] = $this->read();
        [$cached, $traces] = $this->read();
        self::assertStringStartsWith('v1-1-g', (string) $before->describe);
        self::assertSame($before->describe, $cached->describe);
        self::assertContains('describe', $this->cachedNames($traces));

        $this->git($this->dir, 'tag', 'v2');

        [$after, $traces] = $this->read();

        self::assertSame('v2', $after->describe);
        self::assertContains('describe', $this->liveNames($traces));
    }

    public function testANestedLooseTagMissesDescribe(): void
    {
        // A tag in a sub directory does not change the mtime of refs/tags itself.
        $this->git($this->dir, 'tag', 'release/v1');
        $this->commit($this->dir, 'second');
        $this->read();
        [$cached, $traces] = $this->read();
        self::assertContains('describe', $this->cachedNames($traces));
        self::assertStringStartsWith('release/v1-1-g', (string) $cached->describe);

        $this->git($this->dir, 'tag', 'release/v2');

        [$after, $traces] = $this->read();

        self::assertSame('release/v2', $after->describe);
        self::assertContains('describe', $this->liveNames($traces));
    }

    public function testPackingTheTagsMissesDescribeAndAPackedTagIsSeen(): void
    {
        $this->git($this->dir, 'tag', 'v1');
        $this->commit($this->dir, 'second');
        $this->read();
        [, $traces] = $this->read();
        self::assertContains('describe', $this->cachedNames($traces));

        $this->git($this->dir, 'pack-refs', '--all');

        [$packed, $traces] = $this->read();
        self::assertContains('describe', $this->liveNames($traces));
        self::assertStringStartsWith('v1-1-g', (string) $packed->describe);

        // Cached again once the packed state is the one that was read...
        [, $traces] = $this->read();
        self::assertContains('describe', $this->cachedNames($traces));

        // ...and a new tag, packed straight away, changes packed-refs.
        $this->git($this->dir, 'tag', 'v2');
        $this->git($this->dir, 'pack-refs', '--all');

        [$after, $traces] = $this->read();
        self::assertSame('v2', $after->describe);
        self::assertContains('describe', $this->liveNames($traces));
    }

    public function testThereIsNothingToDescribeAndNothingCachedWithoutTags(): void
    {
        $this->read();
        [$info, $traces] = $this->read();

        self::assertNull($info->describe);
        self::assertNotContains('describe', $this->cachedNames($traces));
        self::assertNotContains('describe', $this->liveNames($traces));
    }

    public function testNeverCachesDescribeWithTheReftableBackend(): void
    {
        $this->git($this->dir, 'tag', 'v1');
        mkdir($this->dir . '/.git/reftable');

        $this->read();
        [$info, $traces] = $this->read();

        self::assertSame('v1', $info->describe);
        self::assertContains('describe', $this->liveNames($traces));
        self::assertNotContains('describe', $this->cachedNames($traces));
    }

    public function testAChangedRemoteUrlMissesTheConfig(): void
    {
        $this->git($this->dir, 'remote', 'add', 'origin', 'https://github.com/acme/one.git');
        [$before] = $this->read();
        [, $traces] = $this->read();
        self::assertContains('config', $this->cachedNames($traces));
        self::assertSame('https://github.com/acme/one', $before->remote?->webUrl);

        $this->git($this->dir, 'remote', 'set-url', 'origin', 'https://github.com/acme/two.git');

        [$after, $traces] = $this->read();

        self::assertContains('config', $this->liveNames($traces));
        self::assertSame('https://github.com/acme/two', $after->remote?->webUrl);
    }

    public function testTheCachedConfigHoldsNothingButTheRemoteEntriesWithoutCredentials(): void
    {
        $this->git($this->dir, 'config', 'http.extraheader', 'AUTHORIZATION: basic c2VjcmV0token');
        $this->git($this->dir, 'config', 'credential.helper', 'store');
        $this->git($this->dir, 'remote', 'add', 'origin', 'https://octo:secrettoken@github.com/acme/one.git');

        [$live] = $this->read();
        [$cached, $traces] = $this->read();

        $payload = implode("\n", array_map(static fn ($item): string => (string) json_encode($item, \JSON_UNESCAPED_SLASHES), $this->pool->getValues()));
        self::assertStringContainsString('remote.origin.url', $payload);
        self::assertStringContainsString('github.com/acme/one.git', $payload);
        foreach (['user.email', 'user.name', 'http.', 'credential', 'secret', 'token', 'octo', 'c2Vjc'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $payload, $forbidden);
        }

        self::assertContains('config', $this->cachedNames($traces));
        self::assertSame('https://github.com/acme/one', $live->remote?->webUrl);
        self::assertEquals($live->remote, $cached->remote);
        self::assertEquals($live, $cached);
    }

    public function testAnotherModificationTimeOfTheConfigMissesIt(): void
    {
        $this->read();
        [, $traces] = $this->read();
        self::assertContains('config', $this->cachedNames($traces));

        touch($this->dir . '/.git/config', time() + 100);

        [, $traces] = $this->read();

        self::assertContains('config', $this->liveNames($traces));
    }

    public function testACorruptedCacheIsAMiss(): void
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new \RuntimeException('cache down'));
        $pool->method('save')->willThrowException(new \RuntimeException('cache down'));

        [$repository] = $this->build($pool);
        $info = $repository->read();

        self::assertNotNull($info);
        self::assertSame('main', $info->branch);
        self::assertNotEmpty($info->graphCommits);
    }

    /**
     * @return array{0: GitInfo, 1: list<GitCommandTrace>}
     */
    private function read(int $limit = GitRepository::DEFAULT_GRAPH_COMMIT_LIMIT, bool $pool = true): array
    {
        [$repository, $tracer] = $this->build($pool ? $this->pool : null, $limit);
        $info = $repository->read();
        self::assertNotNull($info);

        return [$info, $tracer->getTraces()];
    }

    /**
     * One tracer, one GitDirectory and one repository per read, like one request of the PHP-FPM.
     *
     * @return array{0: GitRepository, 1: TraceableGitCommandRunner}
     */
    private function build(?CacheItemPoolInterface $pool, int $limit = GitRepository::DEFAULT_GRAPH_COMMIT_LIMIT): array
    {
        $tracer = new TraceableGitCommandRunner(new ProcessGitCommandRunner($this->dir));
        $cache = new GitOutputCache($pool, $tracer);
        $gitDirectory = new GitDirectory($tracer, $this->dir, $cache);

        return [new GitRepository(
            $tracer,
            graphCommitLimit: $limit,
            operationDetector: new OperationDetector($tracer, $this->dir, gitDirectory: $gitDirectory),
            stashCounter: new StashCounter($gitDirectory),
            tagPresence: new TagPresence($gitDirectory),
            gitDirectory: $gitDirectory,
            cache: $cache,
        ), $tracer];
    }

    /**
     * @param list<GitCommandTrace> $traces
     *
     * @return list<string>
     */
    private function cachedNames(array $traces): array
    {
        return $this->names(array_filter($traces, static fn (GitCommandTrace $trace): bool => $trace->cached));
    }

    /**
     * @param list<GitCommandTrace> $traces
     *
     * @return list<string>
     */
    private function liveNames(array $traces): array
    {
        return $this->names(array_filter($traces, static fn (GitCommandTrace $trace): bool => !$trace->cached));
    }

    /**
     * @param array<int, GitCommandTrace> $traces
     *
     * @return list<string>
     */
    private function names(array $traces): array
    {
        $names = array_map(static function (GitCommandTrace $trace): string {
            $name = $trace->arguments[0];

            return match (true) {
                $name === 'log' && \in_array('--topo-order', $trace->arguments, true) => 'graph',
                $name === 'log' => 'unpushed-log',
                $name === 'diff' && \in_array('@{u}...HEAD', $trace->arguments, true) => 'unpushed-diff',
                default => $name,
            };
        }, $traces);
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function subjects(GitInfo $info): array
    {
        $subjects = array_map(static fn ($commit): string => $commit->subject, $info->graphCommits);
        sort($subjects); // the order of commits of diverged branches is not what is tested

        return $subjects;
    }

    private function configureUpstream(): void
    {
        $this->initGitRepository($this->remoteDir, bare: true);
        $this->git($this->dir, 'remote', 'add', 'origin', $this->remoteDir);
        $this->git($this->dir, 'push', '-u', 'origin', 'main');
    }

    private function cloneRemote(): string
    {
        $clone = $this->createTemporaryDirectory('gpb_cache_clone_');
        $this->git($clone, 'clone', $this->remoteDir, '.');
        $this->git($clone, 'config', 'user.email', 'other@example.com');
        $this->git($clone, 'config', 'user.name', 'Other');
        $this->clones[] = $clone;

        return $clone;
    }

    private function commit(string $directory, string $message): void
    {
        // Files are dated in the past: git 2.11 to 2.14 ignore GIT_OPTIONAL_LOCKS, and `git status` rewrites the index
        // (so changes the mtime of `.git`) whenever an entry is "racily clean", which would make the keys depend on timing.
        $file = $directory . '/' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($file, $message . "\n");
        touch($file, time() - 3600);
        $this->git($directory, 'add', '.');
        $this->git($directory, 'commit', '-m', $message);
    }

    private function git(string $directory, string ...$args): void
    {
        (new Process(['git', ...$args], $directory))->mustRun();
    }
}
