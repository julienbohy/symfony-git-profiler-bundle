<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

/**
 * A changed file, immutable.
 *
 * Used both for the working tree (staged / unstaged / untracked) and for files
 * touched by unpushed commits (the "committed" stage).
 */
final readonly class ChangedFile
{
    public function __construct(
        public string $path,
        public FileStatus $status,
        public FileStage $stage,
        public ?string $oldPath = null,
        public int $additions = 0,
        public int $deletions = 0,
    ) {
    }
}
