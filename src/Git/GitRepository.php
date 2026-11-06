<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Parser\DiffParser;
use JulienBohy\GitProfilerBundle\Git\Parser\LogParser;
use JulienBohy\GitProfilerBundle\Git\Parser\StatusParser;
use JulienBohy\GitProfilerBundle\Git\Parser\StatusSnapshot;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reads the Git state of the project with as few git processes as possible
 * (Facade over the command runner and the output parsers).
 */
final readonly class GitRepository implements GitRepositoryInterface
{
    public const DEFAULT_GRAPH_COMMIT_LIMIT = 30;

    private const DIFF_OPTIONS = ['--raw', '--numstat', '-z', '-M'];

    private LoggerInterface $logger;

    public function __construct(
        private GitCommandRunnerInterface $git,
        private StatusParser $statusParser = new StatusParser(),
        private DiffParser $diffParser = new DiffParser(),
        private LogParser $logParser = new LogParser(),
        private int $graphCommitLimit = self::DEFAULT_GRAPH_COMMIT_LIMIT,
        ?LoggerInterface $logger = null,
        private ?OperationDetector $operationDetector = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function read(): ?GitInfo
    {
        try {
            return $this->doRead();
        } catch (\Throwable $e) {
            $this->logger->debug('Git state unavailable: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return null;
        }
    }

    private function doRead(): ?GitInfo
    {
        $status = $this->statusParser->parse(
            $this->git->run(['status', '--porcelain=v2', '--branch', '-z', '--untracked-files=all']),
        );

        if ($status->oid === null) {
            return null; // repository without any commit
        }

        $hasUpstream = $status->upstream !== null && $status->hasUpstreamTracking;

        // `git rev-parse --short` accepts a single revision, and git de-duplicates identical revisions
        // in `git show`: a single line then means that the upstream is at HEAD.
        $shortHashes = $this->lines($this->git->run(['show', '-s', '--format=%h', 'HEAD', ...($hasUpstream ? ['@{u}'] : []), '--']));
        $shortCommit = $shortHashes[0] ?? throw new \UnexpectedValueException('git show returned no hash.');
        $upstreamShortHash = $hasUpstream ? ($shortHashes[1] ?? $shortCommit) : null;

        $workingFiles = $this->workingFiles($status);

        [$hasUpstream, $unpushedCommits, $unpushedFiles, $unpushedHashes] = $this->unpushed($status, $hasUpstream);

        $graphCommits = $this->graphCommits($status->oid, $hasUpstream, $upstreamShortHash, $unpushedHashes);

        return new GitInfo(
            $status->branch,
            $shortCommit,
            $workingFiles !== [],
            $workingFiles,
            $hasUpstream,
            $unpushedCommits,
            $unpushedFiles,
            $graphCommits,
            $hasUpstream ? $status->upstream : null,
            $hasUpstream ? $status->behind : 0,
            $this->operationDetector?->detect(),
        );
    }

    /**
     * @return list<ChangedFile>
     */
    private function workingFiles(StatusSnapshot $status): array
    {
        // Conflicted files come first. `git diff` also reports every unmerged path (status "U" with --cached,
        // "U" plus a bogus "M" against stage 2 without), so they are dropped from the diffs to be listed once.
        $files = $status->conflicts;
        $conflicted = array_flip(array_map(static fn (ChangedFile $file): string => $file->path, $status->conflicts));
        $notConflicted = static fn (ChangedFile $file): bool => !isset($conflicted[$file->path]);

        if ($status->hasStaged) {
            $files = [...$files, ...array_filter($this->diff(['diff', '--cached', ...self::DIFF_OPTIONS], FileStage::Staged, 'staged changes'), $notConflicted)];
        }

        if ($status->hasUnstaged) {
            $files = [...$files, ...array_filter($this->diff(['diff', ...self::DIFF_OPTIONS], FileStage::Unstaged, 'unstaged changes'), $notConflicted)];
        }

        foreach ($status->untracked as $path) {
            $files[] = new ChangedFile($path, FileStatus::Untracked, FileStage::Untracked);
        }

        return $files;
    }

    /**
     * @param list<string> $args
     *
     * @return list<ChangedFile>
     */
    private function diff(array $args, FileStage $stage, string $section): array
    {
        try {
            return $this->diffParser->parse($this->git->run($args), $stage);
        } catch (\Throwable $e) {
            $this->logDegraded($section, $e);

            return [];
        }
    }

    /**
     * Returns [has upstream, commits, files, full hashes].
     *
     * @return array{0: bool, 1: list<UnpushedCommit>, 2: list<ChangedFile>, 3: array<string, true>}
     */
    private function unpushed(StatusSnapshot $status, bool $hasUpstream): array
    {
        if (!$hasUpstream || $status->ahead === 0) {
            return [$hasUpstream, [], [], []];
        }

        try {
            $log = $this->git->run(['log', '--format=' . LogParser::UNPUSHED_FORMAT, '@{u}..HEAD', '--']);
            // Three dots: diff from the merge base, so upstream-only changes of a diverged branch are not shown as local.
            $files = $this->diffParser->parse($this->git->run(['diff', ...self::DIFF_OPTIONS, '@{u}...HEAD', '--']), FileStage::Committed);

            return [true, $this->logParser->unpushedCommits($log), $files, $this->logParser->commitHashes($log)];
        } catch (\Throwable $e) {
            $this->logDegraded('unpushed commits', $e);

            return [false, [], [], []];
        }
    }

    /**
     * @param array<string, true> $unpushedHashes
     *
     * @return list<GraphCommit>
     */
    private function graphCommits(string $headOid, bool $hasUpstream, ?string $upstreamShortHash, array $unpushedHashes): array
    {
        try {
            // The trailing '--' disambiguates the refs from files bearing the same name (e.g. a file named HEAD).
            $output = $this->git->run([
                'log', '--format=' . LogParser::GRAPH_FORMAT, '--topo-order', '-n', (string) $this->graphCommitLimit,
                'HEAD', ...($hasUpstream ? ['@{u}'] : []), '--',
            ]);

            return $this->logParser->graphCommits($output, $headOid, $upstreamShortHash, $unpushedHashes, $hasUpstream);
        } catch (\Throwable $e) {
            $this->logDegraded('commit graph', $e);

            return [];
        }
    }

    private function logDegraded(string $section, \Throwable $e): void
    {
        $this->logger->debug('Git {section} unavailable: {message}', ['section' => $section, 'message' => $e->getMessage(), 'exception' => $e]);
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        return array_values(array_filter(explode("\n", trim($output)), static fn (string $line): bool => $line !== ''));
    }
}
