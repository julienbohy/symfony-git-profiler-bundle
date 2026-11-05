<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

interface GitRepositoryInterface
{
    /**
     * Reads the Git state of the working directory.
     *
     * Returns null when the directory is not a Git repository or when the
     * "git" command is unavailable (graceful degradation, never throws).
     */
    public function read(): ?GitInfo;
}
