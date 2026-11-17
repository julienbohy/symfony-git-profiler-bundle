<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Command;

use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Records every git command with its duration and outcome (Decorator), for the
 * "Git commands" section of the profiler panel.
 *
 * @internal
 */
final class TraceableGitCommandRunner implements ParallelGitCommandRunnerInterface, ResetInterface
{
    /**
     * @var list<GitCommandTrace>
     */
    private array $traces = [];

    public function __construct(private readonly GitCommandRunnerInterface $inner)
    {
    }

    public function run(array $args): string
    {
        $start = hrtime(true);

        try {
            $output = $this->inner->run($args);
        } catch (\Throwable $e) {
            $this->traces[] = new GitCommandTrace($args, $this->elapsedMs($start), false, $e->getMessage());

            throw $e;
        }

        $this->traces[] = new GitCommandTrace($args, $this->elapsedMs($start), true, null);

        return $output;
    }

    /**
     * Each command keeps its own duration (not the wall time of the batch), traced in the order they were submitted.
     */
    public function runParallel(array $commands): array
    {
        if (!$this->inner instanceof ParallelGitCommandRunnerInterface) {
            return $this->runSequentially($commands);
        }

        $results = $this->inner->runParallel($commands);

        foreach ($results as $index => $result) {
            $this->traces[] = new GitCommandTrace($commands[$index], $result->durationMs, $result->isSuccessful(), $result->failure?->getMessage());
        }

        return $results;
    }

    /**
     * The inner runner cannot run commands at once: one after the other, traced by run() as usual.
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
                $results[] = GitCommandResult::success($this->run($args), $this->elapsedMs($start));
            } catch (GitCommandFailedException $e) {
                $results[] = GitCommandResult::failure($e, $this->elapsedMs($start));
            }
        }

        return $results;
    }

    /**
     * A command whose output came from the cache: shown in the panel, without duration, so the number of
     * commands of a request stays explainable.
     *
     * @param list<string> $args
     */
    public function recordCached(array $args): void
    {
        $this->traces[] = new GitCommandTrace($args, 0.0, true, null, true);
    }

    /**
     * @return list<GitCommandTrace>
     */
    public function getTraces(): array
    {
        return $this->traces;
    }

    public function reset(): void
    {
        $this->traces = [];
    }

    private function elapsedMs(int|float $start): float
    {
        return (hrtime(true) - $start) / 1_000_000;
    }
}
