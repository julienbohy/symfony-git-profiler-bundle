<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\DataCollector;

use JulienBohy\GitProfilerBundle\DataCollector\GitDataCollector;
use JulienBohy\GitProfilerBundle\Git\ChangedFile;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\FileStage;
use JulienBohy\GitProfilerBundle\Git\FileStatus;
use JulienBohy\GitProfilerBundle\Git\GitInfo;
use JulienBohy\GitProfilerBundle\Git\GitRepositoryInterface;
use JulienBohy\GitProfilerBundle\Git\GraphCommit;
use JulienBohy\GitProfilerBundle\Git\UnpushedCommit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class GitDataCollectorTest extends TestCase
{
    public function testCollectAloneReadsNothing(): void
    {
        $repository = $this->createMock(GitRepositoryInterface::class);
        $repository->expects(self::never())->method('read');

        (new GitDataCollector($repository))->collect(new Request(), new Response());
    }

    public function testExposesTheBehindCount(): void
    {
        $collector = $this->collectorReturning(new GitInfo('main', 'abc1234', false, hasUpstream: true, upstreamRef: 'origin/main', behindCount: 3));

        self::assertSame(3, $collector->getBehindCommitsCount());
    }

    public function testBehindCountIsNullWithoutUpstream(): void
    {
        self::assertNull($this->collectorReturning(new GitInfo('main', 'abc1234', false))->getBehindCommitsCount());
        self::assertNull($this->collectorReturning(null)->getBehindCommitsCount());
    }

    public function testExposesTheExecutedCommandsAndResetsTheTracer(): void
    {
        $inner = new class implements GitCommandRunnerInterface {
            public function run(array $args): string
            {
                return '';
            }
        };
        $tracer = new TraceableGitCommandRunner($inner);
        $repository = $this->createStub(GitRepositoryInterface::class);
        $repository->method('read')->willReturnCallback(static function () use ($tracer): ?GitInfo {
            $tracer->run(['status', '-z']);

            return null;
        });

        $collector = new GitDataCollector($repository, tracer: $tracer);
        $collector->lateCollect();

        $commands = $collector->getCommands();
        self::assertCount(1, $commands);
        self::assertSame('git status -z', $commands[0]['command']);
        self::assertTrue($commands[0]['successful']);
        self::assertNull($commands[0]['error']);
        self::assertGreaterThanOrEqual(0.0, $collector->getCommandsDuration());

        $collector->reset();

        self::assertSame([], $collector->getCommands());
        self::assertSame([], $tracer->getTraces());
    }

    public function testEachCollectorSharingATracerExposesOnlyItsOwnCommands(): void
    {
        $inner = new class implements GitCommandRunnerInterface {
            public function run(array $args): string
            {
                return '';
            }
        };
        $tracer = new TraceableGitCommandRunner($inner);
        $repository = $this->createStub(GitRepositoryInterface::class);
        $repository->method('read')->willReturnCallback(static function () use ($tracer): ?GitInfo {
            $tracer->run(['status', '-z']);

            return null;
        });

        $sub = new GitDataCollector($repository, tracer: $tracer);
        $main = new GitDataCollector($repository, tracer: $tracer);
        $sub->lateCollect();
        $main->lateCollect();

        self::assertCount(1, $sub->getCommands());
        self::assertCount(1, $main->getCommands());
    }

    public function testNoCommandsWithoutTracer(): void
    {
        self::assertSame([], $this->collectorReturning(null)->getCommands());
        self::assertSame(0.0, $this->collectorReturning(null)->getCommandsDuration());
    }

    public function testCollectsAvailableGitInfo(): void
    {
        $collector = $this->collectorReturning(new GitInfo('main', 'abc1234', true));

        self::assertTrue($collector->isAvailable());
        self::assertSame('main', $collector->getBranch());
        self::assertSame('abc1234', $collector->getShortCommit());
        self::assertTrue($collector->isDirty());
    }

    public function testCollectsExtendedGitInfoAsScalars(): void
    {
        $info = new GitInfo(
            'main',
            'abc1234',
            true,
            workingFiles: [
                // Same path, staged AND unstaged: must be counted only once.
                new ChangedFile('src/App.php', FileStatus::Modified, FileStage::Staged),
                new ChangedFile('src/App.php', FileStatus::Modified, FileStage::Unstaged),
                new ChangedFile('new.txt', FileStatus::Untracked, FileStage::Untracked),
            ],
            hasUpstream: true,
            unpushedCommits: [
                new UnpushedCommit('aaaaaaa', 'feature A', 'Alice', new \DateTimeImmutable('2026-07-13T10:00:00+00:00')),
                new UnpushedCommit('bbbbbbb', 'feature B', 'Bob', new \DateTimeImmutable('2026-07-13T11:00:00+00:00')),
            ],
            unpushedFiles: [
                new ChangedFile('src/App.php', FileStatus::Modified, FileStage::Committed, additions: 3, deletions: 1),
            ],
        );

        $collector = $this->collectorReturning($info);

        self::assertTrue($collector->hasUpstream());
        self::assertSame(2, $collector->getChangedFilesCount());
        self::assertSame(2, $collector->getUnpushedCommitsCount());

        // The data exposed to the template must be scalars (profiler-serializable).
        $workingFiles = $collector->getWorkingFiles();
        self::assertCount(3, $workingFiles);
        self::assertSame('src/App.php', $workingFiles[0]['path']);
        self::assertSame('modified', $workingFiles[0]['status']);
        self::assertSame('Modified', $workingFiles[0]['statusLabel']);
        self::assertSame('staged', $workingFiles[0]['stage']);
        self::assertSame('Staged', $workingFiles[0]['stageLabel']);

        $commits = $collector->getUnpushedCommits();
        self::assertCount(2, $commits);
        self::assertSame('aaaaaaa', $commits[0]['shortHash']);
        self::assertIsString($commits[0]['date']);
        self::assertSame('2026-07-13T10:00:00+00:00', $commits[0]['date']);

        $unpushedFiles = $collector->getUnpushedFiles();
        self::assertCount(1, $unpushedFiles);
        self::assertSame(3, $unpushedFiles[0]['additions']);
    }

    public function testExposesCommitGraphAsScalars(): void
    {
        $rootHash = str_repeat('c', 40);
        $localHash = str_repeat('a', 40);
        $remoteHash = str_repeat('b', 40);

        $info = new GitInfo(
            'main',
            'abc1234',
            false,
            hasUpstream: true,
            graphCommits: [
                new GraphCommit($localHash, 'aaaaaaa', [$rootHash], 'local work', 'Alice', new \DateTimeImmutable('2026-08-14T10:00:00+00:00'), isHead: true),
                new GraphCommit($remoteHash, 'bbbbbbb', [$rootHash], 'remote work', 'Bob', new \DateTimeImmutable('2026-08-14T09:00:00+00:00'), isUpstream: true, isPushed: true),
                new GraphCommit($rootHash, 'ccccccc', [], 'init', 'Alice', new \DateTimeImmutable('2026-08-14T08:00:00+00:00'), isPushed: true),
            ],
            upstreamRef: 'origin/main',
        );

        $collector = $this->collectorReturning($info);

        self::assertSame('origin/main', $collector->getUpstreamRef());
        self::assertSame(2, $collector->getGraphLaneCount());

        $rows = $collector->getGraphRows();
        self::assertCount(3, $rows);

        self::assertSame('aaaaaaa', $rows[0]['shortHash']);
        self::assertSame('local work', $rows[0]['subject']);
        self::assertSame('Alice', $rows[0]['author']);
        self::assertSame('2026-08-14T10:00:00+00:00', $rows[0]['date']);
        self::assertSame(0, $rows[0]['lane']);
        self::assertSame([], $rows[0]['incomingLanes']);
        self::assertSame([['from' => 0, 'to' => 0]], $rows[0]['segments']);
        self::assertTrue($rows[0]['isHead']);
        self::assertFalse($rows[0]['isUpstream']);
        self::assertFalse($rows[0]['isPushed']);

        self::assertSame(1, $rows[1]['lane']);
        self::assertSame([0], $rows[1]['incomingLanes']);
        self::assertSame([['from' => 0, 'to' => 0], ['from' => 1, 'to' => 0]], $rows[1]['segments']);
        self::assertTrue($rows[1]['isUpstream']);
        self::assertTrue($rows[1]['isPushed']);

        self::assertSame(0, $rows[2]['lane']);
        self::assertSame([0], $rows[2]['incomingLanes']);
        self::assertSame([], $rows[2]['segments']);
    }

    public function testDegradesGracefullyWhenNotAGitRepository(): void
    {
        $collector = $this->collectorReturning(null);

        self::assertFalse($collector->isAvailable());
        self::assertNull($collector->getBranch());
        self::assertNull($collector->getShortCommit());
        self::assertFalse($collector->isDirty());
        self::assertSame([], $collector->getWorkingFiles());
        self::assertSame([], $collector->getUnpushedCommits());
        self::assertSame([], $collector->getUnpushedFiles());
        self::assertSame(0, $collector->getChangedFilesCount());
        self::assertSame(0, $collector->getUnpushedCommitsCount());
        self::assertFalse($collector->hasUpstream());
        self::assertSame([], $collector->getGraphRows());
        self::assertSame(0, $collector->getGraphLaneCount());
        self::assertNull($collector->getUpstreamRef());
    }

    public function testExposesStableNameAndTemplate(): void
    {
        self::assertSame('git_profiler', $this->collectorReturning(null)->getName());
        self::assertSame('@GitProfiler/Collector/git.html.twig', GitDataCollector::getTemplate());
    }

    private function collectorReturning(?GitInfo $info): GitDataCollector
    {
        $repository = $this->createStub(GitRepositoryInterface::class);
        $repository->method('read')->willReturn($info);

        $collector = new GitDataCollector($repository);
        $collector->collect(new Request(), new Response());
        $collector->lateCollect();

        return $collector;
    }
}
