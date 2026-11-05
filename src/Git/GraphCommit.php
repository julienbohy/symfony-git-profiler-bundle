<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

/**
 * A commit of the history graph (HEAD and its upstream), immutable.
 */
final readonly class GraphCommit
{
    /**
     * @param list<string> $parentHashes full hashes of the parent commits
     */
    public function __construct(
        public string $hash,
        public string $shortHash,
        public array $parentHashes,
        public string $subject,
        public string $author,
        public \DateTimeImmutable $date,
        public bool $isHead = false,
        public bool $isUpstream = false,
        public bool $isPushed = false,
    ) {
    }
}
