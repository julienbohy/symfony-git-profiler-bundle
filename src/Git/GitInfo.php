<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

use JulienBohy\GitProfilerBundle\Git\Remote\RemoteRepository;

/**
 * Immutable Git state of the current repository.
 */
final readonly class GitInfo
{
    /**
     * @param list<ChangedFile>    $workingFiles    uncommitted files in the working tree, conflicted ones first
     * @param list<UnpushedCommit> $unpushedCommits local commits ahead of the remote
     * @param list<ChangedFile>    $unpushedFiles   files touched by the unpushed commits
     * @param list<GraphCommit>    $graphCommits    recent commits of HEAD and its upstream, log order
     * @param int                  $behindCount     commits on the upstream not in HEAD, as of the last fetch
     * @param ?Operation           $operation       merge, rebase... currently in progress
     * @param ?RemoteRepository    $remote          web repository of the remote, null when unknown or on an unrecognised host
     * @param ?string              $upstreamBranch  name of the upstream branch on the remote ("feature/x" for "origin/feature/x")
     * @param ?string              $headHash        full hash of HEAD
     * @param ?string              $describe        `git describe --tags` of HEAD, e.g. "0.4.0-3-g2897d71"; null without reachable tag
     * @param int                  $stashCount      number of stashes
     */
    public function __construct(
        public string $branch,
        public string $shortCommit,
        public bool $isDirty,
        public array $workingFiles = [],
        public bool $hasUpstream = false,
        public array $unpushedCommits = [],
        public array $unpushedFiles = [],
        public array $graphCommits = [],
        public ?string $upstreamRef = null,
        public int $behindCount = 0,
        public ?Operation $operation = null,
        public ?RemoteRepository $remote = null,
        public ?string $upstreamBranch = null,
        public ?string $headHash = null,
        public ?string $describe = null,
        public int $stashCount = 0,
    ) {
    }
}
