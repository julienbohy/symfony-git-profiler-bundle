<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\DataCollector;

use JulienBohy\GitProfilerBundle\Git\ChangedFile;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandTrace;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\GitRepositoryInterface;
use JulienBohy\GitProfilerBundle\Git\UnpushedCommit;
use JulienBohy\GitProfilerBundle\Graph\CommitGraph;
use JulienBohy\GitProfilerBundle\Graph\CommitGraphLayout;
use JulienBohy\GitProfilerBundle\Graph\CommitGraphLayoutInterface;
use JulienBohy\GitProfilerBundle\Graph\GraphSegment;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

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

        $graph = $this->graphLayout->layout($info?->graphCommits ?? []);

        $this->data = [
            'available' => $info !== null,
            'branch' => $info?->branch,
            'shortCommit' => $info?->shortCommit,
            'dirty' => $info?->isDirty ?? false,
            'workingFiles' => array_map($this->flattenFile(...), $info?->workingFiles ?? []),
            'hasUpstream' => $info?->hasUpstream ?? false,
            'unpushedCommits' => array_map($this->flattenCommit(...), $info?->unpushedCommits ?? []),
            'unpushedFiles' => array_map($this->flattenFile(...), $info?->unpushedFiles ?? []),
            'graphRows' => $this->flattenGraph($graph),
            'graphLaneCount' => $graph->laneCount,
            'upstreamRef' => $info?->upstreamRef,
            'behindCount' => $info?->behindCount ?? 0,
            'commands' => array_map($this->flattenTrace(...), \array_slice($this->tracer?->getTraces() ?? [], $from)),
        ];
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

    public static function getTemplate(): ?string
    {
        return '@GitProfiler/Collector/git.html.twig';
    }

    public function isAvailable(): bool
    {
        return $this->data['available'] ?? false;
    }

    public function getBranch(): ?string
    {
        return $this->data['branch'] ?? null;
    }

    public function getShortCommit(): ?string
    {
        return $this->data['shortCommit'] ?? null;
    }

    public function isDirty(): bool
    {
        return $this->data['dirty'] ?? false;
    }

    /**
     * @return list<array{path: string, status: string, statusLabel: string, stage: string, stageLabel: string, oldPath: ?string, additions: int, deletions: int}>
     */
    public function getWorkingFiles(): array
    {
        return $this->data['workingFiles'] ?? [];
    }

    /**
     * @return list<array{path: string, status: string, statusLabel: string, stage: string, stageLabel: string, oldPath: ?string, additions: int, deletions: int}>
     */
    public function getUnpushedFiles(): array
    {
        return $this->data['unpushedFiles'] ?? [];
    }

    /**
     * @return list<array{shortHash: string, subject: string, author: string, date: string}>
     */
    public function getUnpushedCommits(): array
    {
        return $this->data['unpushedCommits'] ?? [];
    }

    public function hasUpstream(): bool
    {
        return $this->data['hasUpstream'] ?? false;
    }

    /**
     * @return list<array{shortHash: string, subject: string, author: string, date: string, lane: int, incomingLanes: list<int>, segments: list<array{from: int, to: int}>, isHead: bool, isUpstream: bool, isPushed: bool}>
     */
    public function getGraphRows(): array
    {
        return $this->data['graphRows'] ?? [];
    }

    public function getGraphLaneCount(): int
    {
        return $this->data['graphLaneCount'] ?? 0;
    }

    public function getUpstreamRef(): ?string
    {
        return $this->data['upstreamRef'] ?? null;
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
        return $this->hasUpstream() ? ($this->data['behindCount'] ?? 0) : null;
    }

    /**
     * @return list<array{command: string, durationMs: float, successful: bool, error: ?string}>
     */
    public function getCommands(): array
    {
        return $this->data['commands'] ?? [];
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
     * @return array{path: string, status: string, statusLabel: string, stage: string, stageLabel: string, oldPath: ?string, additions: int, deletions: int}
     */
    private function flattenFile(ChangedFile $file): array
    {
        return [
            'path' => $file->path,
            'status' => $file->status->value,
            'statusLabel' => $file->status->label(),
            'stage' => $file->stage->value,
            'stageLabel' => $file->stage->label(),
            'oldPath' => $file->oldPath,
            'additions' => $file->additions,
            'deletions' => $file->deletions,
        ];
    }

    /**
     * @return list<array{shortHash: string, subject: string, author: string, date: string, lane: int, incomingLanes: list<int>, segments: list<array{from: int, to: int}>, isHead: bool, isUpstream: bool, isPushed: bool}>
     */
    private function flattenGraph(CommitGraph $graph): array
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
