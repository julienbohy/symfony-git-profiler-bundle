<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Support;

use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;

/**
 * Answers every command with the same output and counts them.
 */
final class CountingGitRunner implements GitCommandRunnerInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $output)
    {
    }

    public function run(array $args): string
    {
        ++$this->calls;

        return $this->output . "\n";
    }
}
