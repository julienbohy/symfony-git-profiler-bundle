<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Graph;

use JulienBohy\GitProfilerBundle\Git\GraphCommit;
use JulienBohy\GitProfilerBundle\Graph\CommitGraphLayout;
use JulienBohy\GitProfilerBundle\Graph\GraphRow;
use PHPUnit\Framework\TestCase;

final class CommitGraphLayoutTest extends TestCase
{
    public function testEmptyListProducesEmptyGraph(): void
    {
        $graph = (new CommitGraphLayout())->layout([]);

        self::assertSame(0, $graph->laneCount);
        self::assertSame([], $graph->rows);
    }

    public function testLinearHistoryStaysOnSingleLane(): void
    {
        $graph = (new CommitGraphLayout())->layout([
            $this->commit('a', ['b']),
            $this->commit('b', ['c']),
            $this->commit('c', []),
        ]);

        self::assertSame(1, $graph->laneCount);
        self::assertSame([0, 0, 0], $this->lanesOf($graph->rows));
        self::assertSame([[0, 0]], $this->segmentsOf($graph->rows[0]));
        self::assertSame([[0, 0]], $this->segmentsOf($graph->rows[1]));
        self::assertSame([], $this->segmentsOf($graph->rows[2]));
    }

    public function testDivergedBranchesConvergeOnCommonAncestor(): void
    {
        // "a" (local) and "b" (upstream) both fork from "c".
        $graph = (new CommitGraphLayout())->layout([
            $this->commit('a', ['c']),
            $this->commit('b', ['c']),
            $this->commit('c', []),
        ]);

        self::assertSame(2, $graph->laneCount);
        self::assertSame([0, 1, 0], $this->lanesOf($graph->rows));
        // "b" is a tip: no line reaches it from above.
        self::assertSame([[0, 0]], $this->segmentsOf($graph->rows[0]));
        // Both lines converge on "c" in lane 0.
        self::assertSame([[0, 0], [1, 0]], $this->segmentsOf($graph->rows[1]));
    }

    public function testMergeCommitForksTowardsBothParents(): void
    {
        // "a" merges "b" (first parent) and "c" (second parent, also parent of "b").
        $graph = (new CommitGraphLayout())->layout([
            $this->commit('a', ['b', 'c']),
            $this->commit('b', ['c']),
            $this->commit('c', []),
        ]);

        self::assertSame(2, $graph->laneCount);
        self::assertSame([0, 0, 0], $this->lanesOf($graph->rows));
        // The merge dot forks: one line to "b" (lane 0), one opened towards "c" (lane 1).
        self::assertSame([[0, 0], [0, 1]], $this->segmentsOf($graph->rows[0]));
        self::assertSame([[0, 0], [1, 0]], $this->segmentsOf($graph->rows[1]));
    }

    public function testMergeReusesALaneAlreadyExpectingTheSameParent(): void
    {
        // Two tips: "a" (merge of "b" and "c") and "m" (merge of "d" and "c").
        // When "m" is laid out, lane 1 already waits for "c": its second-parent
        // line joins that lane instead of opening a new one.
        $graph = (new CommitGraphLayout())->layout([
            $this->commit('a', ['b', 'c']),
            $this->commit('m', ['d', 'c']),
            $this->commit('b', []),
            $this->commit('d', []),
            $this->commit('c', []),
        ]);

        self::assertSame(3, $graph->laneCount);
        self::assertSame([0, 2, 0, 2, 1], $this->lanesOf($graph->rows));
        self::assertSame([[0, 0], [0, 1]], $this->segmentsOf($graph->rows[0]));
        // Pass-throughs for lanes 0 and 1, plus the merge line joining lane 1 from the "m" dot.
        self::assertSame([[0, 0], [1, 1], [2, 2], [2, 1]], $this->segmentsOf($graph->rows[1]));
    }

    public function testParentBeyondTruncationIsIgnored(): void
    {
        // "b" has a parent that is not part of the (truncated) list.
        $graph = (new CommitGraphLayout())->layout([
            $this->commit('a', ['b']),
            $this->commit('b', ['beyond-truncation']),
        ]);

        self::assertSame(1, $graph->laneCount);
        self::assertSame([0, 0], $this->lanesOf($graph->rows));
        self::assertSame([[0, 0]], $this->segmentsOf($graph->rows[0]));
        self::assertSame([], $this->segmentsOf($graph->rows[1]));
    }

    public function testMergeAtTheTruncationBoundaryDoesNotOpenAPhantomLane(): void
    {
        // The window ends on a merge whose parents are all beyond the truncation:
        // the lane opened for the second parent is never drawn and must not count.
        $graph = (new CommitGraphLayout())->layout([
            $this->commit('a', ['b']),
            $this->commit('b', ['out1', 'out2']),
        ]);

        self::assertSame(1, $graph->laneCount);
    }

    public function testRowsKeepTheirCommit(): void
    {
        $commit = $this->commit('a', []);

        $graph = (new CommitGraphLayout())->layout([$commit]);

        self::assertSame($commit, $graph->rows[0]->commit);
    }

    /**
     * @param list<string> $parents
     */
    private function commit(string $hash, array $parents): GraphCommit
    {
        return new GraphCommit(
            $hash,
            substr($hash . '0000000', 0, 7),
            $parents,
            'subject of ' . $hash,
            'Alice',
            new \DateTimeImmutable('2026-08-14T10:00:00+00:00'),
        );
    }

    /**
     * @param list<GraphRow> $rows
     *
     * @return list<int>
     */
    private function lanesOf(array $rows): array
    {
        return array_map(static fn (GraphRow $row) => $row->lane, $rows);
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function segmentsOf(GraphRow $row): array
    {
        return array_map(static fn ($segment) => [$segment->fromLane, $segment->toLane], $row->segments);
    }
}
