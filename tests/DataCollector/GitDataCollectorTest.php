<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\DataCollector;

use JulienBohy\GitProfilerBundle\DataCollector\GitDataCollector;
use JulienBohy\GitProfilerBundle\Git\ChangedFile;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\ConflictKind;
use JulienBohy\GitProfilerBundle\Git\FileStage;
use JulienBohy\GitProfilerBundle\Git\FileStatus;
use JulienBohy\GitProfilerBundle\Git\GitInfo;
use JulienBohy\GitProfilerBundle\Git\GitRepositoryInterface;
use JulienBohy\GitProfilerBundle\Git\GraphCommit;
use JulienBohy\GitProfilerBundle\Git\Operation;
use JulienBohy\GitProfilerBundle\Git\OperationKind;
use JulienBohy\GitProfilerBundle\Git\UnpushedCommit;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testDetectsADetachedHead(): void
    {
        self::assertTrue($this->collectorReturning(new GitInfo('HEAD', 'abc1234', false))->isDetached());
        self::assertFalse($this->collectorReturning(new GitInfo('main', 'abc1234', false))->isDetached());
        self::assertFalse($this->collectorReturning(null)->isDetached());
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

    public function testExposesTheOperationInProgressAsPlainData(): void
    {
        $collector = $this->collectorReturning(new GitInfo(
            'HEAD',
            'abc1234',
            true,
            workingFiles: [
                new ChangedFile('src/App.php', FileStatus::Conflicted, FileStage::Unmerged, conflict: ConflictKind::DeletedByThem),
                new ChangedFile('README.md', FileStatus::Modified, FileStage::Unstaged),
            ],
            operation: new Operation(OperationKind::Rebase, 3, 7, 'feature/x', '1a2b3c4'),
        ));

        self::assertTrue($collector->hasOperation());
        self::assertSame([
            'kind' => 'rebase',
            'label' => 'Rebasing',
            'shortLabel' => 'rebasing',
            'step' => 3,
            'total' => 7,
            'headName' => 'feature/x',
            'onto' => '1a2b3c4',
            'target' => null,
            'hints' => ['git rebase --continue', 'git rebase --abort'],
        ], $collector->getOperation());
        self::assertSame('feature/x', $collector->getRebasingBranch());
        self::assertSame('rebasing 3/7', $collector->getOperationToolbarLabel());
        self::assertSame(1, $collector->getConflictsCount());
        self::assertSame(2, $collector->getChangedFilesCount());

        $file = $collector->getWorkingFiles()[0];
        self::assertSame('conflicted', $file['status']);
        self::assertSame('Conflict — deleted by them', $file['statusLabel']);
        self::assertSame('unmerged', $file['stage']);
        self::assertSame('UD', $file['conflict']);
        self::assertNull($collector->getWorkingFiles()[1]['conflict']);
    }

    public function testNoOperation(): void
    {
        $collector = $this->collectorReturning(new GitInfo('main', 'abc1234', false));

        self::assertFalse($collector->hasOperation());
        self::assertNull($collector->getOperation());
        self::assertNull($collector->getRebasingBranch());
        self::assertNull($collector->getOperationToolbarLabel());
        self::assertSame(0, $collector->getConflictsCount());
        self::assertSame('normal', $collector->getToolbarStatus());

        $unavailable = $this->collectorReturning(null);
        self::assertNull($unavailable->getOperation());
        self::assertSame('normal', $unavailable->getToolbarStatus());
    }

    public function testOnlyARebaseExposesARebasingBranch(): void
    {
        $collector = $this->collectorReturning(new GitInfo('HEAD', 'abc1234', false, operation: new Operation(OperationKind::Merge, headName: 'feature')));
        self::assertNull($collector->getRebasingBranch());

        $detached = $this->collectorReturning(new GitInfo('HEAD', 'abc1234', false, operation: new Operation(OperationKind::Rebase, 1, 2)));
        self::assertNull($detached->getRebasingBranch());
    }

    public function testToolbarStatus(): void
    {
        $conflict = new ChangedFile('a.txt', FileStatus::Conflicted, FileStage::Unmerged, conflict: ConflictKind::BothModified);

        self::assertSame('yellow', $this->collectorReturning(new GitInfo('main', 'abc1234', false, operation: new Operation(OperationKind::Bisect)))->getToolbarStatus());
        self::assertSame('red', $this->collectorReturning(new GitInfo('main', 'abc1234', true, [$conflict], operation: new Operation(OperationKind::Merge)))->getToolbarStatus());
        self::assertSame('red', $this->collectorReturning(new GitInfo('main', 'abc1234', true, [$conflict]))->getToolbarStatus());
    }

    #[DataProvider('toolbarLabels')]
    public function testOperationToolbarLabel(Operation $operation, string $expected): void
    {
        self::assertSame($expected, $this->collectorReturning(new GitInfo('main', 'abc1234', false, operation: $operation))->getOperationToolbarLabel());
    }

    /**
     * @return iterable<string, array{Operation, string}>
     */
    public static function toolbarLabels(): iterable
    {
        yield 'merge' => [new Operation(OperationKind::Merge), 'merging'];
        yield 'rebase' => [new Operation(OperationKind::Rebase, 3, 7), 'rebasing 3/7'];
        yield 'rebase without counters' => [new Operation(OperationKind::Rebase), 'rebasing'];
        yield 'rebase with a step only' => [new Operation(OperationKind::Rebase, 3), 'rebasing'];
        yield 'rebase with a total only' => [new Operation(OperationKind::Rebase, null, 7), 'rebasing'];
        yield 'am' => [new Operation(OperationKind::ApplyMailbox, 2, 5), 'am 2/5'];
        yield 'cherry-pick' => [new Operation(OperationKind::CherryPick), 'cherry-picking'];
        yield 'revert' => [new Operation(OperationKind::Revert), 'reverting'];
        yield 'bisect' => [new Operation(OperationKind::Bisect), 'bisecting'];
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
