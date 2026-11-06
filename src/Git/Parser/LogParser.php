<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Parser;

use JulienBohy\GitProfilerBundle\Git\GraphCommit;
use JulienBohy\GitProfilerBundle\Git\UnpushedCommit;

/**
 * Owns the `git log --format` strings and parses their output. The subject is
 * always the last field: as free text it may contain the separator, and the
 * explode limit keeps it whole. A malformed line is skipped, never the whole list.
 *
 * @internal
 */
final class LogParser
{
    /**
     * ASCII unit separator.
     */
    public const FIELD_SEPARATOR = "\x1f";

    public const UNPUSHED_FORMAT = '%H' . self::FIELD_SEPARATOR . '%an' . self::FIELD_SEPARATOR . '%aI' . self::FIELD_SEPARATOR . '%s';

    public const GRAPH_FORMAT = '%H' . self::FIELD_SEPARATOR . '%P' . self::FIELD_SEPARATOR . '%an' . self::FIELD_SEPARATOR . '%aI' . self::FIELD_SEPARATOR . '%s';

    /**
     * @return list<UnpushedCommit>
     */
    public function unpushedCommits(string $output): array
    {
        $commits = [];

        foreach ($this->lines($output) as $line) {
            try {
                [$hash, $author, $date, $subject] = explode(self::FIELD_SEPARATOR, $line, 4);

                $commits[] = new UnpushedCommit(substr($hash, 0, 7), $subject, $author, new \DateTimeImmutable($date));
            } catch (\Throwable) {
                // Malformed line: skip this commit, keep the others.
            }
        }

        return $commits;
    }

    /**
     * @return array<string, true> full hashes of the commits (first field of each line)
     */
    public function commitHashes(string $output): array
    {
        $hashes = [];

        foreach ($this->lines($output) as $line) {
            $hashes[explode(self::FIELD_SEPARATOR, $line)[0]] = true;
        }

        return $hashes;
    }

    /**
     * @param array<string, true> $unpushedHashes
     *
     * @return list<GraphCommit>
     */
    public function graphCommits(
        string $output,
        string $headOid,
        ?string $upstreamShortHash,
        array $unpushedHashes,
        bool $hasUpstream,
    ): array {
        $commits = [];

        foreach ($this->lines($output) as $line) {
            try {
                [$hash, $parents, $author, $date, $subject] = explode(self::FIELD_SEPARATOR, $line, 5);

                $commits[] = new GraphCommit(
                    $hash,
                    substr($hash, 0, 7),
                    $parents === '' ? [] : explode(' ', $parents),
                    $subject,
                    $author,
                    new \DateTimeImmutable($date),
                    isHead: $hash === $headOid,
                    isUpstream: $upstreamShortHash !== null && str_starts_with($hash, $upstreamShortHash),
                    isPushed: $hasUpstream && !isset($unpushedHashes[$hash]),
                );
            } catch (\Throwable) {
                // Malformed line: skip this commit, keep the graph.
            }
        }

        return $commits;
    }

    /**
     * @return array<int, string> non-empty lines (keys are not renumbered: callers only iterate)
     */
    private function lines(string $output): array
    {
        return array_filter(explode("\n", trim($output)), static fn (string $line): bool => $line !== '');
    }
}
