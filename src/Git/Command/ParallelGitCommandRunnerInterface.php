<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Command;

/**
 * An optional capability of a runner: starting several commands at once. The bundle runs its commands
 * in parallel when the runner has it, and one after the other through run() otherwise.
 */
interface ParallelGitCommandRunnerInterface extends GitCommandRunnerInterface
{
    /**
     * Starts every command before waiting for any of them, so they run concurrently, each with its own
     * timeout. A failing command never affects the others: it is reported in its own result, never thrown.
     *
     * @param list<list<string>> $commands the arguments of each command, without the leading "git"
     *
     * @return list<GitCommandResult> one result per command, in the order of $commands
     */
    public function runParallel(array $commands): array;
}
