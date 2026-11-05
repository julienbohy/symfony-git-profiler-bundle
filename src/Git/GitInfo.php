<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

/**
 * Immutable Git state of the current repository.
 */
final readonly class GitInfo
{
    /**
     * @param list<ChangedFile>    $workingFiles    uncommitted files in the working tree
     * @param list<UnpushedCommit> $unpushedCommits local commits ahead of the remote
     * @param list<ChangedFile>    $unpushedFiles   files touched by the unpushed commits
     * @param list<GraphCommit>    $graphCommits    recent commits of HEAD and its upstream, log order
     * @param int                  $behindCount     commits on the upstream not in HEAD, as of the last fetch
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
    ) {
    }
}
