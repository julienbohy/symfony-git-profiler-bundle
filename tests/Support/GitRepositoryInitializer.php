<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Support;

use Symfony\Component\Process\Process;

trait GitRepositoryInitializer
{
    /**
     * `git init -b main` needs git 2.28: point HEAD at `main` explicitly so the suite also runs on older gits (2.11+).
     */
    private function initGitRepository(string $directory, bool $bare = false): void
    {
        (new Process(['git', 'init', ...($bare ? ['--bare'] : []), $directory]))->mustRun();
        (new Process(['git', 'symbolic-ref', 'HEAD', 'refs/heads/main'], $directory))->mustRun();
    }
}
