<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

/**
 * A local commit ahead of the remote (not pushed yet), immutable.
 */
final readonly class UnpushedCommit
{
    public function __construct(
        public string $shortHash,
        public string $subject,
        public string $author,
        public \DateTimeImmutable $date,
    ) {
    }
}
