<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

/**
 * Location of a changed file in the Git lifecycle.
 */
enum FileStage: string
{
    case Staged = 'staged';
    case Unstaged = 'unstaged';
    case Untracked = 'untracked';
    case Committed = 'committed';

    public function label(): string
    {
        return match ($this) {
            self::Staged => 'Staged',
            self::Unstaged => 'Unstaged',
            self::Untracked => 'Untracked',
            self::Committed => 'Committed',
        };
    }
}
