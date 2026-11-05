<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Graph;

use JulienBohy\GitProfilerBundle\Git\GraphCommit;

/**
 * A commit placed on the graph: its lane and the segments towards the next row.
 */
final readonly class GraphRow
{
    /**
     * @param list<GraphSegment> $segments segments joining this row to the next one
     */
    public function __construct(
        public GraphCommit $commit,
        public int $lane,
        public array $segments,
    ) {
    }
}
