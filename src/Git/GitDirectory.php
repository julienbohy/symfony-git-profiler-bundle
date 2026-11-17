<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

use JulienBohy\GitProfilerBundle\Git\Cache\GitCacheKeys;
use JulienBohy\GitProfilerBundle\Git\Cache\GitOutputCache;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;

/**
 * Locates the git directory with a single `git rev-parse --git-dir` (`--absolute-git-dir` needs git 2.13),
 * kept for the lifetime of the process and, when a cache is given, across requests (keyed by the state of the
 * `.git` entry). It covers linked worktrees (`.git/worktrees/<name>`) and submodules.
 *
 * Refs shared by all the worktrees (stashes, tags, remotes config) live in the common directory, which is
 * read from the `commondir` file of a linked worktree's git directory (`git rev-parse --git-common-dir` has
 * odd relative paths before git 2.13): it is the git directory itself everywhere else.
 */
final class GitDirectory
{
    /**
     * The command that locates the git directory (see {@see self::locate()} for its output).
     */
    public const LOCATE_COMMAND = ['rev-parse', '--git-dir'];

    private ?string $path = null;

    private ?string $commonPath = null;

    /**
     * The cache key computed by {@see self::peek()}, before the command starts: the state it describes is the
     * one the command is going to see.
     */
    private ?string $lookupKey = null;

    public function __construct(
        private readonly GitCommandRunnerInterface $git,
        private readonly string $workingDirectory,
        private readonly GitOutputCache $cache = new GitOutputCache(),
        private readonly GitCacheKeys $keys = new GitCacheKeys(),
    ) {
    }

    /**
     * @throws \UnexpectedValueException when the directory cannot be found
     * @throws \Throwable                when git fails
     */
    public function path(): string
    {
        if ($this->peek()) {
            return (string) $this->path;
        }

        return $this->locate($this->git->run(self::LOCATE_COMMAND));
    }

    /**
     * True when the directory is known without running anything: kept from an earlier call, or read from the
     * cache. Never throws; an unusable cached value (the directory is gone) is ignored.
     */
    public function peek(): bool
    {
        if ($this->path !== null) {
            return true;
        }

        $this->lookupKey = $key = $this->cacheKey();
        $cached = $key !== null ? $this->cache->lookup($key, self::LOCATE_COMMAND) : null;
        if ($cached === null) {
            return false;
        }

        try {
            $this->resolve($cached);
        } catch (\UnexpectedValueException) {
            return false;
        }

        return true;
    }

    /**
     * Keeps the directory reported by {@see self::LOCATE_COMMAND}, run by the caller (possibly in a parallel batch).
     *
     * @throws \UnexpectedValueException when the directory cannot be found
     */
    public function locate(string $output): string
    {
        $path = $this->resolve($output);

        $key = $this->lookupKey ?? $this->cacheKey();
        $this->lookupKey = null;
        if ($key !== null) {
            $this->cache->store($key, $output);
        }

        return $path;
    }

    private function cacheKey(): ?string
    {
        return $this->cache->isEnabled() ? $this->keys->gitDirectory(self::LOCATE_COMMAND, $this->workingDirectory) : null;
    }

    private function resolve(string $output): string
    {
        $path = trim($output);
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
