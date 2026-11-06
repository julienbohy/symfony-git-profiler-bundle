<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

/**
 * Kind of an unmerged path, from the two-letter "XY" code of `git status --porcelain=v2`
 * (X is our side, Y is their side).
 */
enum ConflictKind: string
{
    case BothDeleted = 'DD';
    case AddedByUs = 'AU';
    case DeletedByThem = 'UD';
    case AddedByThem = 'UA';
    case DeletedByUs = 'DU';
    case BothAdded = 'AA';
    case BothModified = 'UU';

    public function label(): string
    {
        return match ($this) {
            self::BothDeleted => 'both deleted',
            self::AddedByUs => 'added by us',
            self::DeletedByThem => 'deleted by them',
            self::AddedByThem => 'added by them',
            self::DeletedByUs => 'deleted by us',
            self::BothAdded => 'both added',
            self::BothModified => 'both modified',
        };
    }
}
