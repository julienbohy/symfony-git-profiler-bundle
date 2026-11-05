<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Graph;

/**
 * A line segment joining a lane on one row to a lane on the next row.
 */
final readonly class GraphSegment
{
    public function __construct(
        public int $fromLane,
        public int $toLane,
    ) {
    }
}
