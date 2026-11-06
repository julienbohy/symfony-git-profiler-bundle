<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\DataCollector;

use JulienBohy\GitProfilerBundle\Git\ChangedFile;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandTrace;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\FileStatus;
use JulienBohy\GitProfilerBundle\Git\GitInfo;
use JulienBohy\GitProfilerBundle\Git\GitRepositoryInterface;
use JulienBohy\GitProfilerBundle\Git\Operation;
use JulienBohy\GitProfilerBundle\Git\OperationKind;
use JulienBohy\GitProfilerBundle\Git\Remote\RemoteRepository;
use JulienBohy\GitProfilerBundle\Git\UnpushedCommit;
use JulienBohy\GitProfilerBundle\Graph\CommitGraph;
use JulienBohy\GitProfilerBundle\Graph\CommitGraphLayout;
use JulienBohy\GitProfilerBundle\Graph\CommitGraphLayoutInterface;
use JulienBohy\GitProfilerBundle\Graph\GraphSegment;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Symfony\Component\VarDumper\Cloner\Data;

/**
 * @phpstan-type CollectedOperation array{kind: string, label: string, shortLabel: string, step: ?int, total: ?int, headName: ?string, onto: ?string, target: ?string, hints: list<string>}
 * @phpstan-type CollectedFile array{path: string, status: string, statusLabel: string, stage: string, stageLabel: string, oldPath: ?string, additions: int, deletions: int, conflict: ?string}
 * @phpstan-type CollectedData array{
 *     available?: bool,
 *     branch?: ?string,
 *     shortCommit?: ?string,
 *     dirty?: bool,
 *     workingFiles?: list<CollectedFile>,
 *     hasUpstream?: bool,
 *     unpushedCommits?: list<array{shortHash: string, subject: string, author: string, date: string}>,
 *     unpushedFiles?: list<CollectedFile>,
 *     graphRows?: list<array{shortHash: string, subject: string, author: string, date: string, lane: int, incomingLanes: list<int>, segments: list<array{from: int, to: int}>, isHead: bool, isUpstream: bool, isPushed: bool, url?: ?string}>,
 *     graphLaneCount?: int,
 *     upstreamRef?: ?string,
 *     behindCount?: int,
 *     operation?: ?CollectedOperation,
 *     remote?: ?array{provider: string, url: string},
 *     upstreamUrl?: ?string,
 *     compareUrl?: ?string,
 *     describe?: ?string,
 *     stashCount?: int,
 *     commands?: list<array{command: string, durationMs: float, successful: bool, error: ?string}>,
 * }
 */
final class GitDataCollector extends AbstractDataCollector implements LateDataCollectorInterface
{
    public function __construct(
        private readonly GitRepositoryInterface $repository,
        private readonly CommitGraphLayoutInterface $graphLayout = new CommitGraphLayout(),
        private readonly ?TraceableGitCommandRunner $tracer = null,
    ) {
    }

    /**
     * Git is read in lateCollect(), on kernel.terminate: after the response is sent.
     */
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
    }

    public function lateCollect(): void
    {
        // The tracer is shared by every collector clone (sub-requests included): keep only our own commands.
        $from = \count($this->tracer?->getTraces() ?? []);

        $info = $this->repository->read();

        $graph = $this->graphLayout->layout($info->graphCommits ?? []);

        $this->data = [
            'available' => $info !== null,
            'branch' => $info?->branch,
            'shortCommit' => $info?->shortCommit,
            'dirty' => $info->isDirty ?? false,
            'workingFiles' => array_map($this->flattenFile(...), $info->workingFiles ?? []),
            'hasUpstream' => $info->hasUpstream ?? false,
            'unpushedCommits' => array_map($this->flattenCommit(...), $info->unpushedCommits ?? []),
            'unpushedFiles' => array_map($this->flattenFile(...), $info->unpushedFiles ?? []),
            'graphRows' => $this->flattenGraph($graph, $info?->remote),
            'graphLaneCount' => $graph->laneCount,
            'upstreamRef' => $info?->upstreamRef,
            'behindCount' => $info->behindCount ?? 0,
            'operation' => $info?->operation !== null ? $this->flattenOperation($info->operation) : null,
            'remote' => $info?->remote !== null ? ['provider' => $info->remote->provider->label(), 'url' => $info->remote->webUrl] : null,
            'upstreamUrl' => $this->upstreamUrl($info),
            'compareUrl' => $this->compareUrl($info),
            'describe' => $info?->describe,
            'stashCount' => $info->stashCount ?? 0,
            'commands' => array_map($this->flattenTrace(...), \array_slice($this->tracer?->getTraces() ?? [], $from)),
        ];
    }

    /**
     * @return CollectedData
     */
    private function collected(): array
    {
        // The parent property is untyped on Symfony 6.4 and typed `array|Data` from 7.x, so it cannot be
        // redeclared here: narrow it locally instead. Keys are absent until lateCollect() ran.
        if ($this->data instanceof Data) {
            return [];
        }

        /** @var CollectedData $collected */
        $collected = $this->data;

        return $collected;
    }

    public function reset(): void
    {
        parent::reset();
        $this->tracer?->reset();
    }

    public function getName(): string
    {
        return 'git_profiler';
    }

    public static function getTemplate(): string
    {
        return '@GitProfiler/Collector/git.html.twig';
    }

    public function isAvailable(): bool
    {
        return $this->collected()['available'] ?? false;
    }

    public function getBranch(): ?string
    {
        return $this->collected()['branch'] ?? null;
    }

    /**
     * True when HEAD points at a commit rather than a branch (git reports the branch as "HEAD").
     */
    public function isDetached(): bool
    {
        return $this->isAvailable() && $this->getBranch() === 'HEAD';
    }

    public function getShortCommit(): ?string
    {
        return $this->collected()['shortCommit'] ?? null;
    }

    public function isDirty(): bool
    {
        return $this->collected()['dirty'] ?? false;
    }

    /**
     * @return list<CollectedFile>
     */
    public function getWorkingFiles(): array
    {
        return $this->collected()['workingFiles'] ?? [];
    }

    /**
     * @return list<CollectedFile>
     */
    public function getUnpushedFiles(): array
    {
        return $this->collected()['unpushedFiles'] ?? [];
    }

    /**
     * @return list<array{shortHash: string, subject: string, author: string, date: string}>
     */
    public function getUnpushedCommits(): array
    {
        return $this->collected()['unpushedCommits'] ?? [];
    }

    public function hasUpstream(): bool
    {
        return $this->collected()['hasUpstream'] ?? false;
    }

    /**
     * @return list<array{shortHash: string, subject: string, author: string, date: string, lane: int, incomingLanes: list<int>, segments: list<array{from: int, to: int}>, isHead: bool, isUpstream: bool, isPushed: bool, url?: ?string}>
     */
    public function getGraphRows(): array
    {
        return $this->collected()['graphRows'] ?? [];
    }

    public function getGraphLaneCount(): int
    {
        return $this->collected()['graphLaneCount'] ?? 0;
    }

    public function getUpstreamRef(): ?string
    {
        return $this->collected()['upstreamRef'] ?? null;
    }

    /**
     * The hosting service of the remote ("GitHub"...) and the web URL of the repository, null when unknown.
     *
     * @return ?array{provider: string, url: string}
     */
    public function getRemote(): ?array
    {
        return $this->collected()['remote'] ?? null;
    }

    /**
     * Web page of the upstream branch on the remote.
     */
    public function getUpstreamUrl(): ?string
    {
        return $this->collected()['upstreamUrl'] ?? null;
    }

    /**
     * Web page comparing HEAD with the upstream branch: only when the upstream is ahead and HEAD is not.
     */
    public function getCompareUrl(): ?string
    {
        return $this->collected()['compareUrl'] ?? null;
    }

    /**
     * `git describe --tags` of HEAD, e.g. "0.4.0" or "0.4.0-3-g2897d71"; null without a reachable tag.
     */
    public function getDescribe(): ?string
    {
        return $this->collected()['describe'] ?? null;
    }

    public function getStashCount(): int
    {
        return $this->collected()['stashCount'] ?? 0;
    }

    public function getUnpushedCommitsCount(): int
    {
        return \count($this->getUnpushedCommits());
    }

    /**
     * Commits on the upstream not in HEAD, as of the last fetch; null without upstream.
     */
    public function getBehindCommitsCount(): ?int
    {
        return $this->hasUpstream() ? ($this->collected()['behindCount'] ?? 0) : null;
    }

    /**
     * The operation in progress (merge, rebase, cherry-pick...), null when there is none.
     *
     * @return ?CollectedOperation
     */
    public function getOperation(): ?array
    {
        return $this->collected()['operation'] ?? null;
    }

    public function hasOperation(): bool
    {
        return $this->getOperation() !== null;
    }

    /**
     * Number of files with an unresolved merge conflict.
     */
    public function getConflictsCount(): int
    {
        return \count(array_filter($this->getWorkingFiles(), static fn (array $file): bool => $file['status'] === FileStatus::Conflicted->value));
    }

    /**
     * The branch being rebased: HEAD is detached during a rebase, this is the branch to display instead.
     */
    public function getRebasingBranch(): ?string
    {
        $operation = $this->getOperation();

        return $operation !== null && $operation['kind'] === OperationKind::Rebase->value ? $operation['headName'] : null;
    }

    /**
     * Toolbar block status: red with conflicts, yellow while an operation is in progress.
     *
     * @return 'normal'|'yellow'|'red'
     */
    public function getToolbarStatus(): string
    {
        return match (true) {
            $this->getConflictsCount() > 0 => 'red',
            $this->hasOperation() => 'yellow',
            default => 'normal',
        };
    }

    /**
     * Short toolbar text of the operation in progress, e.g. "rebasing 3/7".
     */
    public function getOperationToolbarLabel(): ?string
    {
        $operation = $this->getOperation();
        if ($operation === null) {
            return null;
        }

        return $operation['step'] !== null && $operation['total'] !== null
            ? \sprintf('%s %d/%d', $operation['shortLabel'], $operation['step'], $operation['total'])
            : $operation['shortLabel'];
    }

    /**
     * @return list<array{command: string, durationMs: float, successful: bool, error: ?string}>
     */
    public function getCommands(): array
    {
        return $this->collected()['commands'] ?? [];
    }

    public function getCommandsDuration(): float
    {
        return (float) array_sum(array_column($this->getCommands(), 'durationMs'));
    }

    /**
     * Number of distinct working-tree files (a partially staged file shows up
     * both as staged AND unstaged but is counted only once).
     */
    public function getChangedFilesCount(): int
    {
        return \count(array_unique(array_column($this->getWorkingFiles(), 'path')));
    }

    /**
     * @return CollectedOperation
     */
    private function flattenOperation(Operation $operation): array
    {
        return [
            'kind' => $operation->kind->value,
            'label' => $operation->kind->label(),
            'shortLabel' => $operation->kind->shortLabel(),
            'step' => $operation->step,
            'total' => $operation->total,
            'headName' => $operation->headName,
            'onto' => $operation->onto,
            'target' => $operation->target,
            'hints' => $operation->kind->hints(),
        ];
    }

    /**
     * @return CollectedFile
     */
    private function flattenFile(ChangedFile $file): array
    {
        return [
            'path' => $file->path,
            'status' => $file->status->value,
            'statusLabel' => $file->conflict !== null ? \sprintf('%s — %s', $file->status->label(), $file->conflict->label()) : $file->status->label(),
            'stage' => $file->stage->value,
            'stageLabel' => $file->stage->label(),
            'oldPath' => $file->oldPath,
            'additions' => $file->additions,
            'deletions' => $file->deletions,
            'conflict' => $file->conflict?->value,
        ];
    }

    private function upstreamUrl(?GitInfo $info): ?string
    {
        return $info?->remote !== null && $info->upstreamBranch !== null ? $info->remote->branchUrl($info->upstreamBranch) : null;
    }

    private function compareUrl(?GitInfo $info): ?string
    {
        // Behind and not ahead: HEAD is an ancestor of the upstream, so it exists on the remote.
        if ($info?->remote === null || $info->upstreamBranch === null || $info->headHash === null || $info->unpushedCommits !== [] || $info->behindCount <= 0) {
            return null;
        }

        return $info->remote->compareUrl($info->headHash, $info->upstreamBranch);
    }

    /**
     * Only commits known to be on the remote get a URL: the others would be a 404.
     *
     * @return list<array{shortHash: string, subject: string, author: string, date: string, lane: int, incomingLanes: list<int>, segments: list<array{from: int, to: int}>, isHead: bool, isUpstream: bool, isPushed: bool, url: ?string}>
     */
    private function flattenGraph(CommitGraph $graph, ?RemoteRepository $remote): array
    {
        $rows = [];
        $previousSegments = [];

        foreach ($graph->rows as $row) {
            $incoming = array_map(static fn (GraphSegment $segment) => $segment->toLane, $previousSegments);

            $rows[] = [
                'shortHash' => $row->commit->shortHash,
                'subject' => $row->commit->subject,
                'author' => $row->commit->author,
                'date' => $row->commit->date->format(\DateTimeInterface::ATOM),
                'lane' => $row->lane,
                'incomingLanes' => array_values(array_unique($incoming)),
                'segments' => array_map(
                    static fn (GraphSegment $segment) => ['from' => $segment->fromLane, 'to' => $segment->toLane],
                    $row->segments,
                ),
                'isHead' => $row->commit->isHead,
                'isUpstream' => $row->commit->isUpstream,
                'isPushed' => $row->commit->isPushed,
                'url' => $remote !== null && $row->commit->isPushed ? $remote->commitUrl($row->commit->hash) : null,
            ];

            $previousSegments = $row->segments;
        }

        return $rows;
    }

    /**
     * @return array{command: string, durationMs: float, successful: bool, error: ?string}
     */
    private function flattenTrace(GitCommandTrace $trace): array
    {
        return [
            'command' => $trace->commandLine(),
            'durationMs' => $trace->durationMs,
            'successful' => $trace->successful,
            'error' => $trace->error,
        ];
    }

    /**
     * @return array{shortHash: string, subject: string, author: string, date: string}
     */
    private function flattenCommit(UnpushedCommit $commit): array
    {
        return [
            'shortHash' => $commit->shortHash,
            'subject' => $commit->subject,
            'author' => $commit->author,
            'date' => $commit->date->format(\DateTimeInterface::ATOM),
        ];
    }
}
