<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Parser;

use JulienBohy\GitProfilerBundle\Git\ChangedFile;

/**
 * Parsed output of `git status --porcelain=v2 --branch -z`.
 *
 * @internal
 */
final readonly class StatusSnapshot
{
    /**
     * @param ?string           $oid                 HEAD commit, null when the repository has no commit
     * @param bool              $hasUpstreamTracking whether ahead/behind are known (`# branch.ab` present)
     * @param list<string>      $untracked
     * @param list<ChangedFile> $conflicts           unmerged paths (`u` records)
     */
    public function __construct(
        public ?string $oid,
        public string $branch,
        public ?string $upstream,
        public int $ahead,
        public int $behind,
        public bool $hasUpstreamTracking,
        public bool $hasStaged,
        public bool $hasUnstaged,
        public array $untracked,
        public array $conflicts = [],
    ) {
    }
}
