<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

/**
 * Kind of multi-step Git operation that can be left in progress in a repository.
 */
enum OperationKind: string
{
    case Merge = 'merge';
    case Rebase = 'rebase';
    case ApplyMailbox = 'am';
    case CherryPick = 'cherry-pick';
    case Revert = 'revert';
    case Bisect = 'bisect';

    /**
     * Present participle, e.g. "Rebasing".
     */
    public function label(): string
    {
        return match ($this) {
            self::Merge => 'Merging',
            self::Rebase => 'Rebasing',
            self::ApplyMailbox => 'Applying patches (am)',
            self::CherryPick => 'Cherry-picking',
            self::Revert => 'Reverting',
            self::Bisect => 'Bisect in progress',
        };
    }

    /**
     * Lowercase form for the toolbar, e.g. "rebasing".
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Merge => 'merging',
            self::Rebase => 'rebasing',
            self::ApplyMailbox => 'am',
            self::CherryPick => 'cherry-picking',
            self::Revert => 'reverting',
            self::Bisect => 'bisecting',
        };
    }

    /**
     * Commands that continue or abort the operation.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        return match ($this) {
            // `git commit` rather than `git merge --continue`, which needs git 2.12.
            self::Merge => ['git commit', 'git merge --abort'],
            self::Rebase => ['git rebase --continue', 'git rebase --abort'],
            self::ApplyMailbox => ['git am --continue', 'git am --skip', 'git am --abort'],
            self::CherryPick => ['git cherry-pick --continue', 'git cherry-pick --abort'],
            self::Revert => ['git revert --continue', 'git revert --abort'],
            self::Bisect => ['git bisect reset'],
        };
    }
}
