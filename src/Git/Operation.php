<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

/**
 * A multi-step Git operation left in progress (rebase, merge, cherry-pick...), immutable.
 */
final readonly class Operation
{
    /**
     * @param ?int    $step     current step of a rebase / am
     * @param ?int    $total    number of steps of a rebase / am
     * @param ?string $headName branch being rebased, without "refs/heads/"
     * @param ?string $onto     short hash the rebase is replaying commits onto
     * @param ?string $target   merge source (first line of MERGE_MSG) or short hash of the picked / reverted commit
     */
    public function __construct(
        public OperationKind $kind,
        public ?int $step = null,
        public ?int $total = null,
        public ?string $headName = null,
        public ?string $onto = null,
        public ?string $target = null,
    ) {
    }
}
