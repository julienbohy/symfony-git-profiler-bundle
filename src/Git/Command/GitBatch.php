<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Command;

use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;
use JulienBohy\GitProfilerBundle\Git\Cache\GitOutputCache;

/**
 * A set of named git commands started together: the ones whose output is cached are not run at all.
 *
 * @internal
 */
final class GitBatch
{
    /**
     * @var array<string, array{0: list<string>, 1: ?string, 2: ?\Closure(string): string}> name => [arguments, cache key, what to store]
     */
    private array $commands = [];

    /**
     * @var array<string, string> name => cache key, of the commands that were actually run
     */
    private array $toStore = [];

    /**
     * @var array<string, GitCommandResult>
     */
    private array $results = [];

    public function __construct(
        private readonly GitCommandRunnerInterface $git,
        private readonly GitOutputCache $cache,
    ) {
    }

    /**
     * @param list<string>              $args
     * @param ?string                   $key      null: never cached
     * @param ?\Closure(string): string $storable reduces the output to what may be written to the cache (default: all of it)
     */
    public function add(string $name, array $args, ?string $key = null, ?\Closure $storable = null): void
    {
        $this->commands[$name] = [$args, $key, $storable];
    }

    /**
     * @return array<string, GitCommandResult>
     */
    public function run(): array
    {
        $live = [];

        foreach ($this->commands as $name => [$args, $key, $storable]) {
            $cached = $key !== null ? $this->cache->lookup($key, $args) : null;

            if ($cached !== null) {
                $this->results[$name] = GitCommandResult::cached($cached);

                continue;
            }

            $live[] = $name;
        }

        if ($live !== []) {
            $commands = array_map(fn (string $name): array => $this->commands[$name][0], $live);
            $results = $this->git instanceof ParallelGitCommandRunnerInterface ? $this->git->runParallel($commands) : $this->runSequentially($commands);

            foreach ($live as $position => $name) {
                $result = $results[$position] ?? throw new \LogicException('The runner returned fewer results than commands.');
                $this->results[$name] = $result;

                if ($result->isSuccessful() && $this->commands[$name][1] !== null) {
                    $this->toStore[$name] = $this->commands[$name][1];
                }
            }
        }

        return $this->results;
    }

    /**
     * The runner has no parallel capability: same results, one command after the other.
     *
     * @param list<list<string>> $commands
     *
     * @return list<GitCommandResult>
     */
    private function runSequentially(array $commands): array
    {
        $results = [];

        foreach ($commands as $args) {
            $start = hrtime(true);

            try {
                $results[] = GitCommandResult::success($this->git->run($args), (hrtime(true) - $start) / 1_000_000);
            } catch (GitCommandFailedException $e) {
                $results[] = GitCommandResult::failure($e, (hrtime(true) - $start) / 1_000_000);
            }
        }

        return $results;
    }

    /**
     * Caches the output of the commands that ran successfully. Separate from run() so the caller can first
     * make sure the state the keys were computed from is still the one the commands saw.
     */
    public function storeSuccesses(): void
    {
        foreach ($this->toStore as $name => $key) {
            $storable = $this->commands[$name][2];
            $output = $this->results[$name]->output();
            $this->cache->store($key, $storable !== null ? $storable($output) : $output);
        }

        $this->toStore = [];
    }
}
