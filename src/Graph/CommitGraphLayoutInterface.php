<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Graph;

use JulienBohy\GitProfilerBundle\Git\GraphCommit;

interface CommitGraphLayoutInterface
{
    /**
     * @param list<GraphCommit> $commits in log order (children before parents)
     */
    public function layout(array $commits): CommitGraph;
}
