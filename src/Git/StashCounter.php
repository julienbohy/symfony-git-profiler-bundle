<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

use JulienBohy\GitProfilerBundle\Git\Parser\ReflogParser;

/**
 * Counts the stashes without running git: `git stash list` is the reflog of `refs/stash`, a plain file
 * (`logs/refs/stash`, one line per stash) in the common git directory, shared by all the linked worktrees.
 *
 * Needs the files ref backend: with the reftable backend (git 2.45+, opt-in) there is no such file and
 * the count is 0.
 */
final readonly class StashCounter
{
    public function __construct(
        private GitDirectory $gitDirectory,
        private ReflogParser $parser = new ReflogParser(),
    ) {
    }

    /**
     * @throws \Throwable when the git directory cannot be located or the reflog read
     */
    public function count(): int
    {
        $file = $this->gitDirectory->commonPath() . '/logs/refs/stash';

        // Missing: no stash was ever made (or the reflog was deleted by the last `git stash drop`/`clear`).
        if (!is_file($file) || !is_readable($file)) {
            return 0;
        }

        return $this->parser->countEntries((string) file_get_contents($file));
    }
}
