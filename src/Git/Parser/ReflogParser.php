<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Parser;

/**
 * Reads a reflog file, one entry per line. The reflog of `refs/stash` is the list of stashes.
 *
 * @internal
 */
final class ReflogParser
{
    public function countEntries(string $content): int
    {
        return \count(array_filter(explode("\n", $content), static fn (string $line): bool => $line !== ''));
    }
}
