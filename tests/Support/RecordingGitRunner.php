<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Support;

use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandResult;
use JulienBohy\GitProfilerBundle\Git\Command\ParallelGitCommandRunnerInterface;

/**
 * Delegates to a real runner and records which commands ran alone and which started together.
 */
final class RecordingGitRunner implements ParallelGitCommandRunnerInterface
{
    /**
     * @var list<list<string>> first argument of each command run alone
     */
    public array $sequential = [];

    /**
     * @var list<list<string>> first argument of the commands of each batch
     */
    public array $batches = [];

    /**
     * @param ?string   $failing    an argument whose commands fail (simulated)
     * @param ?\Closure $beforeShow called right before the `git show` of a batch
     */
    public function __construct(
        private readonly ParallelGitCommandRunnerInterface $inner,
        private readonly ?string $failing = null,
        private readonly ?\Closure $beforeShow = null,
    ) {
    }

    public function run(array $args): string
    {
        $this->sequential[] = [$args[0]];

        return $this->inner->run($args);
    }

    public function runParallel(array $commands): array
    {
        $this->batches[] = array_map(static fn (array $args): string => $args[0], $commands);

        $results = [];
        foreach ($commands as $args) {
            if ($this->failing !== null && \in_array($this->failing, $args, true)) {
                $results[] = GitCommandResult::failure(GitCommandFailedException::fromArguments($args, 'simulated'), 1.0);

                continue;
            }

            if ($args[0] === 'show' && $this->beforeShow !== null) {
                ($this->beforeShow)();
            }
            $results[] = $this->inner->runParallel([$args])[0];
        }

        return $results;
    }
}
