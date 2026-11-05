<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Graph;

/**
 * The laid-out commit graph, ready to be drawn.
 */
final readonly class CommitGraph
{
    /**
     * @param list<GraphRow> $rows
     */
    public function __construct(
        public int $laneCount,
        public array $rows,
    ) {
    }
}
