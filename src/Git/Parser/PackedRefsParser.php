<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Parser;

/**
 * Reads the lines of `packed-refs`: "<hash> <refname>", "^<hash>" (peeled tag) and a "# pack-refs" header.
 *
 * @internal
 */
final class PackedRefsParser
{
    public function isTagLine(string $line): bool
    {
        return preg_match('#^[0-9a-f]{40}(?:[0-9a-f]{24})? refs/tags/#', $line) === 1;
    }
}
