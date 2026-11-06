<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;

/**
 * Locates the git directory with a single `git rev-parse --git-dir` (`--absolute-git-dir` needs git 2.13),
 * kept for the lifetime of the process. It covers linked worktrees (`.git/worktrees/<name>`) and submodules.
 *
 * Refs shared by all the worktrees (stashes, tags, remotes config) live in the common directory, which is
 * read from the `commondir` file of a linked worktree's git directory (`git rev-parse --git-common-dir` has
 * odd relative paths before git 2.13): it is the git directory itself everywhere else.
 */
final class GitDirectory
{
    private ?string $path = null;

    private ?string $commonPath = null;

    public function __construct(
        private readonly GitCommandRunnerInterface $git,
        private readonly string $workingDirectory,
    ) {
    }

    /**
     * @throws \UnexpectedValueException when the directory cannot be found
     * @throws \Throwable                when git fails
     */
    public function path(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }

        $path = trim($this->git->run(['rev-parse', '--git-dir']));
        if ($path === '') {
            throw new \UnexpectedValueException('git rev-parse --git-dir returned nothing.');
        }

        // Relative to the working directory of the git process (".git" at the root of a repository).
        $directory = $this->isAbsolute($path) ? $path : rtrim($this->workingDirectory, '/\\') . '/' . $path;

        if (!is_dir($directory)) {
            throw new \UnexpectedValueException(\sprintf('Git directory "%s" does not exist.', $directory));
        }

        return $this->path = $directory;
    }

    /**
     * @throws \UnexpectedValueException when the directory cannot be found
     * @throws \Throwable                when git fails
     */
    public function commonPath(): string
    {
        return $this->commonPath ??= $this->resolveCommonPath();
    }

    private function resolveCommonPath(): string
    {
        $gitDirectory = $this->path();
        $file = $gitDirectory . '/commondir';

        $content = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        $line = $content === false ? '' : trim(explode("\n", $content, 2)[0]);
        if ($line === '') {
            return $gitDirectory;
        }

        // Relative to the worktree's git directory ("../.." for .git/worktrees/<name>).
        $directory = $this->isAbsolute($line) ? $line : $gitDirectory . '/' . $line;

        if (!is_dir($directory)) {
            throw new \UnexpectedValueException(\sprintf('Common git directory "%s" does not exist.', $directory));
        }

        return $directory;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
