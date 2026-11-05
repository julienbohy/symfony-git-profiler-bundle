<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

/**
 * Nature of a changed file (compared to its reference version).
 */
enum FileStatus: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
    case Renamed = 'renamed';
    case Untracked = 'untracked';

    public function label(): string
    {
        return match ($this) {
            self::Added => 'Added',
            self::Modified => 'Modified',
            self::Deleted => 'Deleted',
            self::Renamed => 'Renamed',
            self::Untracked => 'Untracked',
        };
    }
}
