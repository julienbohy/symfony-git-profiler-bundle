<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Command;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Records every git command with its duration and outcome (Decorator), for the
 * "Git commands" section of the profiler panel.
 *
 * @internal
 */
final class TraceableGitCommandRunner implements GitCommandRunnerInterface, ResetInterface
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
