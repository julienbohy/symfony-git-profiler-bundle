<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Command;

use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;

interface GitCommandRunnerInterface
{
    /**
     * Runs "git <args>" in the project directory and returns its raw standard output.
     *
     * @param list<string> $args arguments, without the leading "git"
     *
     * @throws GitCommandFailedException when git cannot run, times out or exits with a non-zero status
     */
    public function run(array $args): string;
}
