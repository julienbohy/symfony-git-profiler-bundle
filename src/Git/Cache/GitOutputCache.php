<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Cache;

use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Cross-request cache of the raw output of git commands (PSR-6). Without a pool it caches nothing.
 *
 * Only strings are stored, so every parsing path is the same whether the output was cached or not.
 * It never throws: a broken cache (unwritable directory, corrupted entry) is a miss.
 *
 * @internal
 */
final readonly class GitOutputCache
{
    public function __construct(
        private ?CacheItemPoolInterface $pool = null,
        private ?TraceableGitCommandRunner $tracer = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->pool !== null;
    }

    /**
     * @param list<string> $args the command the output belongs to, shown as a "cached" row in the profiler
     */
    public function lookup(string $key, array $args): ?string
    {
        try {
            $item = $this->pool?->getItem($key);
            $output = $item?->get();
        } catch (\Throwable) {
            return null;
        }

        if ($item === null || !$item->isHit() || !\is_string($output)) {
            return null;
        }

        $this->tracer?->recordCached($args);

        return $output;
    }

    public function store(string $key, string $output): void
    {
        try {
            if ($this->pool !== null) {
                $this->pool->save($this->pool->getItem($key)->set($output));
            }
        } catch (\Throwable) {
            // Not cached: the next request runs the command again.
        }
    }
}
