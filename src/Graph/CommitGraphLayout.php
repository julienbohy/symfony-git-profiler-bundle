<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Graph;

use JulienBohy\GitProfilerBundle\Git\GraphCommit;

/**
 * Assigns a lane to each commit and computes the segments between rows.
 *
 * Classic sweep over the commits in log order (children before parents):
 * each lane "waits" for a hash; a commit lands on the first lane waiting
 * for it (or a free lane for branch tips), continues on its first parent
 * and opens — or joins — a lane per additional parent.
 */
final class CommitGraphLayout implements CommitGraphLayoutInterface
{
    /**
     * @param list<GraphCommit> $commits ordered children first (git log order)
     */
    public function layout(array $commits): CommitGraph
    {
        if ($commits === []) {
            return new CommitGraph(0, []);
        }

        /** @var array<int, ?string> $lanes hash each lane is waiting for (null = free) */
        $lanes = [];
        /** @var array<int, int> $origins visual position, on the current row, of each lane's line */
        $origins = [];
        /** @var list<array{0: int, 1: int}> $joins merge lines [dot position, joined lane] */
        $joins = [];

        /** @var array<int, int> $rowLanes */
        $rowLanes = [];
        /** @var array<int, list<GraphSegment>> $rowSegments */
        $rowSegments = [];

        foreach ($commits as $row => $commit) {
            $matching = array_keys($lanes, $commit->hash, true);
            $lane = $matching === [] ? $this->freeLane($lanes) : min($matching);

            if ($row > 0) {
                $rowSegments[$row - 1] = $this->segmentsInto($lanes, $origins, $joins, $commit->hash, $lane);
            }

            foreach ($matching as $index) {
                $lanes[$index] = null;
            }

            $origins = [];
            $joins = [];

            $lanes[$lane] = $commit->parentHashes[0] ?? null;

            foreach (\array_slice($commit->parentHashes, 1) as $parent) {
                $existing = array_keys($lanes, $parent, true);
                if ($existing !== []) {
                    $joins[] = [$lane, min($existing)];
                    continue;
                }

                $new = $this->freeLane($lanes);
                $lanes[$new] = $parent;
                $origins[$new] = $lane;
            }

            foreach ($lanes as $index => $hash) {
                if ($hash !== null && !isset($origins[$index])) {
                    $origins[$index] = $index;
                }
            }

            $rowLanes[$row] = $lane;
        }

        $rowSegments[\count($commits) - 1] = [];

        $rows = [];
        foreach ($commits as $row => $commit) {
            $rows[] = new GraphRow($commit, $rowLanes[$row], $rowSegments[$row]);
        }

        return new CommitGraph($this->laneCount($rows), $rows);
    }

    /**
     * Number of lanes actually drawn (dots and segments); a lane opened for a
     * parent beyond the truncated window is never drawn and must not count.
     *
     * @param list<GraphRow> $rows
     */
    private function laneCount(array $rows): int
    {
        $maxLane = 0;

        foreach ($rows as $row) {
            $maxLane = max($maxLane, $row->lane);
            foreach ($row->segments as $segment) {
                $maxLane = max($maxLane, $segment->fromLane, $segment->toLane);
            }
        }

        return $maxLane + 1;
    }

    /**
     * @param array<int, ?string> $lanes
     */
    private function freeLane(array $lanes): int
    {
        foreach ($lanes as $index => $hash) {
            if ($hash === null) {
                return $index;
            }
        }

        return \count($lanes);
    }

    /**
     * Segments joining the previous row to the commit placed on $lane.
     *
     * Lines waiting for that commit converge on its lane; the others go
     * straight down. Merge lines start from their dot and join their lane.
     *
     * @param array<int, ?string>         $lanes
     * @param array<int, int>             $origins
     * @param list<array{0: int, 1: int}> $joins
     *
     * @return list<GraphSegment>
     */
    private function segmentsInto(array $lanes, array $origins, array $joins, string $hash, int $lane): array
    {
        $segments = [];

        foreach ($lanes as $index => $expected) {
            if ($expected === null) {
                continue;
            }

            $segments[] = new GraphSegment($origins[$index], $expected === $hash ? $lane : $index);
        }

        foreach ($joins as [$from, $joined]) {
            $segments[] = new GraphSegment($from, $lanes[$joined] === $hash ? $lane : $joined);
        }

        return $segments;
    }
}
