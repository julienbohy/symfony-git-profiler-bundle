<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

use JulienBohy\GitProfilerBundle\Git\Cache\GitCacheKeys;
use JulienBohy\GitProfilerBundle\Git\Cache\GitOutputCache;
use JulienBohy\GitProfilerBundle\Git\Cache\RemoteConfigFilter;
use JulienBohy\GitProfilerBundle\Git\Command\GitBatch;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandResult;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Parser\DiffParser;
use JulienBohy\GitProfilerBundle\Git\Parser\GitConfigParser;
use JulienBohy\GitProfilerBundle\Git\Parser\LogParser;
use JulienBohy\GitProfilerBundle\Git\Parser\StatusParser;
use JulienBohy\GitProfilerBundle\Git\Parser\StatusSnapshot;
use JulienBohy\GitProfilerBundle\Git\Remote\RemoteRepository;
use JulienBohy\GitProfilerBundle\Git\Remote\RemoteUrlParser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reads the Git state of the project with as few git processes as possible
 * (Facade over the command runner and the output parsers).
 *
 * `status` runs first, alone. Then the HEAD/upstream lookup, the diffs and the git directory run in parallel,
 * and the commands whose output only depends on the history (graph, unpushed commits, tags, remote config)
 * run in the same batch, or in a second one when their cache key needs the result of the first, and not at all
 * when their output is cached. At most three sequential rounds: about 2 x timeout once the history is cached.
 */
final readonly class GitRepository implements GitRepositoryInterface
{
    public const DEFAULT_GRAPH_COMMIT_LIMIT = 30;

    private const DIFF_OPTIONS = ['--raw', '--numstat', '-z', '-M'];

    private const CONFIG_COMMAND = ['config', '--null', '--list'];

    private const DESCRIBE_COMMAND = ['describe', '--tags', '--abbrev=7', 'HEAD'];

    private LoggerInterface $logger;

    public function __construct(
        private GitCommandRunnerInterface $git,
        private StatusParser $statusParser = new StatusParser(),
        private DiffParser $diffParser = new DiffParser(),
        private LogParser $logParser = new LogParser(),
        private int $graphCommitLimit = self::DEFAULT_GRAPH_COMMIT_LIMIT,
        ?LoggerInterface $logger = null,
        private ?OperationDetector $operationDetector = null,
        private GitConfigParser $configParser = new GitConfigParser(),
        private RemoteUrlParser $remoteUrlParser = new RemoteUrlParser(),
        private ?StashCounter $stashCounter = null,
        private ?TagPresence $tagPresence = null,
        private ?GitDirectory $gitDirectory = null,
        private GitOutputCache $cache = new GitOutputCache(),
        private GitCacheKeys $keys = new GitCacheKeys(),
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

        $headOid = $status->oid;
        $hasUpstream = $status->upstream !== null && $status->hasUpstreamTracking;

        $upstreamOid = null;
        $queued = [];

        // Known without any process (memoized or cached): the keys that only need the common git directory can
        // be computed before the first batch.
        $directory = $this->gitDirectory;
        $directoryKnown = $directory?->peek() ?? false;
        $common = $directory !== null && $directoryKnown ? $this->commonDirectory($directory, null) : null;

        // First wave: HEAD and upstream (always live), the diffs (never cached), the git directory when it is
        // not known yet, and everything that does not wait for them.
        $batch = new GitBatch($this->git, $this->cache);
        // A single `git show` gives the full and short hashes of HEAD and of the upstream. `git rev-parse --short`
        // accepts a single revision, and git de-duplicates identical revisions: a single line then means that
        // the upstream is at HEAD.
        $batch->add('show', ['show', '-s', '--format=%H %h', 'HEAD', ...($hasUpstream ? ['@{u}'] : []), '--']);
        if ($status->hasStaged) {
            $batch->add('staged', ['diff', '--cached', ...self::DIFF_OPTIONS]);
        }
        if ($status->hasUnstaged) {
            $batch->add('unstaged', ['diff', ...self::DIFF_OPTIONS]);
        }
        if ($directory !== null && !$directoryKnown) {
            $batch->add('git-dir', GitDirectory::LOCATE_COMMAND);
        }
        $this->queueHistory($batch, $status, $common, $upstreamOid, false, $queued);
        $results = $batch->run();

        [$shownHead, $shortCommit, $shownUpstream, $upstreamShortHash] = $this->shown($results['show']->output(), $hasUpstream);
        $upstreamOid = $shownUpstream;
        if ($directory !== null && isset($results['git-dir'])) {
            $common = $this->commonDirectory($directory, $results['git-dir']);
        }

        // Cache what ran only when HEAD did not move between `status` and `show` (the keys come from `status`).
        $stable = $shownHead === $headOid;
        if ($stable) {
            $batch->storeSuccesses();
        }

        // Second wave: what needed the common git directory or the upstream oid.
        $next = new GitBatch($this->git, $this->cache);
        $this->queueHistory($next, $status, $common, $upstreamOid, true, $queued);
        $results += $next->run();
        if ($stable) {
            $next->storeSuccesses();
        }

        $workingFiles = $this->workingFiles($status, $results['staged'] ?? null, $results['unstaged'] ?? null);

        [$hasUpstream, $unpushedCommits, $unpushedFiles, $unpushedHashes] = $this->unpushed($status, $hasUpstream, $results['unpushed-log'] ?? null, $results['unpushed-diff'] ?? null);

        [$remote, $upstreamBranch] = $this->remote($status->branch, $hasUpstream ? $status->upstream : null, $results['config']);

        $graphCommits = $this->graphCommits($headOid, $hasUpstream, $hasUpstream ? $upstreamShortHash : null, $unpushedHashes, $results['graph']);

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
            $remote,
            $upstreamBranch,
            $status->oid,
            $this->describe($results['describe'] ?? null),
            $this->stashCount(),
        );
    }

    /**
     * Queues the commands that depend on the history only and can start now. A command whose cache key needs
     * the common git directory or the upstream oid waits for the next wave ($final: everything left runs, not
     * cached if its key is still unknown).
     *
     * @param array<string, true> $queued the commands already queued, by section
     */
    private function queueHistory(GitBatch $batch, StatusSnapshot $status, ?string $common, ?string $upstreamOid, bool $final, array &$queued): void
    {
        $headOid = (string) $status->oid;
        $hasUpstream = $status->upstream !== null && $status->hasUpstreamTracking;
        $cached = $this->cache->isEnabled();
        $haveHistory = $common !== null && (!$hasUpstream || $upstreamOid !== null);

        if (!isset($queued['config']) && (!$cached || $common !== null || $final)) {
            $queued['config'] = true;
            $batch->add('config', self::CONFIG_COMMAND, $cached && $common !== null ? $this->keys->config(self::CONFIG_COMMAND, $common) : null, (new RemoteConfigFilter())->filter(...));
        }

        if (!isset($queued['describe']) && ($common !== null || $this->gitDirectory === null || $final)) {
            $queued['describe'] = true;
            // `git describe` fails without any tag: do not run it when it is certain there is none.
            if ($this->tagPresence === null || $this->tagPresence->mightHaveTags()) {
                $batch->add('describe', self::DESCRIBE_COMMAND, $cached && $common !== null ? $this->keys->describe(self::DESCRIBE_COMMAND, $common, $headOid) : null);
            }
        }

        if (isset($queued['history']) || ($cached && !$haveHistory && !$final)) {
            return;
        }
        $queued['history'] = true;

        $keyed = $cached && $common !== null && $haveHistory;

        // The trailing '--' disambiguates the refs from files bearing the same name (e.g. a file named HEAD).
        $graph = ['log', '--format=' . LogParser::GRAPH_FORMAT, '--topo-order', '-n', (string) $this->graphCommitLimit, 'HEAD', ...($hasUpstream ? ['@{u}'] : []), '--'];
        $batch->add('graph', $graph, $keyed ? $this->keys->history($graph, $common, $headOid, $upstreamOid) : null);

        if ($hasUpstream && $status->ahead > 0) {
            $log = ['log', '--format=' . LogParser::UNPUSHED_FORMAT, '@{u}..HEAD', '--'];
            // Three dots: diff from the merge base, so upstream-only changes of a diverged branch are not shown as local.
            $diff = ['diff', ...self::DIFF_OPTIONS, '@{u}...HEAD', '--'];
            $batch->add('unpushed-log', $log, $keyed ? $this->keys->history($log, $common, $headOid, $upstreamOid) : null);
            $batch->add('unpushed-diff', $diff, $keyed ? $this->keys->history($diff, $common, $headOid, $upstreamOid) : null);
        }
    }

    /**
     * Parses `git show -s --format="%H %h"`.
     *
     * @return array{0: string, 1: string, 2: ?string, 3: ?string} HEAD oid, HEAD short hash, upstream oid, upstream short hash
     */
    private function shown(string $output, bool $hasUpstream): array
    {
        $commits = [];
        foreach ($this->lines($output) as $line) {
            if (preg_match('/^([0-9a-f]{40}(?:[0-9a-f]{24})?) ([0-9a-f]+)$/', $line, $matches) !== 1) {
                throw new \UnexpectedValueException('git show returned an unexpected line.');
            }

            $commits[] = [$matches[1], $matches[2]];
        }

        $head = $commits[0] ?? throw new \UnexpectedValueException('git show returned no hash.');
        $upstream = $hasUpstream ? ($commits[1] ?? $head) : null;

        return [$head[0], $head[1], $upstream[0] ?? null, $upstream[1] ?? null];
    }

    /**
     * Keeps the git directory located by the batch (when it ran) and returns the common git directory, null
     * when it cannot be determined.
     */
    private function commonDirectory(GitDirectory $directory, ?GitCommandResult $located): ?string
    {
        try {
            if ($located !== null) {
                $directory->locate($located->output());
            }

            return $directory->commonPath();
        } catch (\Throwable $e) {
            $this->logDegraded('git directory', $e);

            return null;
        }
    }

    /**
     * @return list<ChangedFile>
     */
    private function workingFiles(StatusSnapshot $status, ?GitCommandResult $staged, ?GitCommandResult $unstaged): array
    {
        // Conflicted files come first. `git diff` also reports every unmerged path (status "U" with --cached,
        // "U" plus a bogus "M" against stage 2 without), so they are dropped from the diffs to be listed once.
        $files = $status->conflicts;
        $conflicted = array_flip(array_map(static fn (ChangedFile $file): string => $file->path, $status->conflicts));
        $notConflicted = static fn (ChangedFile $file): bool => !isset($conflicted[$file->path]);

        if ($staged !== null) {
            $files = [...$files, ...array_filter($this->diff($staged, FileStage::Staged, 'staged changes'), $notConflicted)];
        }

        if ($unstaged !== null) {
            $files = [...$files, ...array_filter($this->diff($unstaged, FileStage::Unstaged, 'unstaged changes'), $notConflicted)];
        }

        foreach ($status->untracked as $path) {
            $files[] = new ChangedFile($path, FileStatus::Untracked, FileStage::Untracked);
        }

        return $files;
    }

    /**
     * @return list<ChangedFile>
     */
    private function diff(GitCommandResult $result, FileStage $stage, string $section): array
    {
        try {
            return $this->diffParser->parse($result->output(), $stage);
        } catch (\Throwable $e) {
            $this->logDegraded($section, $e);

            return [];
        }
    }

    /**
     * Returns [has upstream, commits, files, full hashes]. Without both commands (not ahead of the upstream)
     * there is nothing to show; when one fails, the branch is considered to have no upstream.
     *
     * @return array{0: bool, 1: list<UnpushedCommit>, 2: list<ChangedFile>, 3: array<string, true>}
     */
    private function unpushed(StatusSnapshot $status, bool $hasUpstream, ?GitCommandResult $log, ?GitCommandResult $diff): array
    {
        if (!$hasUpstream || $status->ahead === 0 || $log === null || $diff === null) {
            return [$hasUpstream, [], [], []];
        }

        try {
            $output = $log->output();
            $files = $this->diffParser->parse($diff->output(), FileStage::Committed);

            return [true, $this->logParser->unpushedCommits($output), $files, $this->logParser->commitHashes($output)];
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
    private function graphCommits(string $headOid, bool $hasUpstream, ?string $upstreamShortHash, array $unpushedHashes, GitCommandResult $result): array
    {
        try {
            return $this->logParser->graphCommits($result->output(), $headOid, $upstreamShortHash, $unpushedHashes, $hasUpstream);
        } catch (\Throwable $e) {
            $this->logDegraded('commit graph', $e);

            return [];
        }
    }

    /**
     * The web repository of the remote of the branch (its `origin` without one) and the name of the upstream
     * branch on that remote. One `git config` process; nothing is linked when the remote or its host is unknown.
     *
     * @return array{0: ?RemoteRepository, 1: ?string}
     */
    private function remote(string $branch, ?string $upstream, GitCommandResult $result): array
    {
        try {
            $configured = $this->configParser->remote($result->output(), $branch);
            $repository = $configured !== null ? $this->remoteUrlParser->parse($configured->url) : null;

            if ($configured === null || $repository === null) {
                return [null, null];
            }

            return [$repository, $upstream !== null ? $configured->branchOf($upstream) : null];
        } catch (\Throwable $e) {
            $this->logDegraded('remote', $e);

            return [null, null];
        }
    }

    private function describe(?GitCommandResult $result): ?string
    {
        if ($result === null) {
            return null; // certain there is no tag
        }

        try {
            return $this->lines($result->output())[0] ?? null;
        } catch (\Throwable $e) {
            $this->logDegraded('version', $e); // no tag reachable from HEAD ("No names found" or "cannot describe anything")

            return null;
        }
    }

    private function stashCount(): int
    {
        try {
            return $this->stashCounter?->count() ?? 0;
        } catch (\Throwable $e) {
            $this->logDegraded('stashes', $e);

            return 0;
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
