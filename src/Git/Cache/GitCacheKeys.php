<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Cache;

/**
 * Cache keys of the git commands whose output depends on the history only. They are deliberately
 * conservative: a key changes whenever anything that could change the output may have changed, and is
 * null (do not cache) when that cannot be told. A miss only costs one process, a stale hit is a bug.
 *
 * Every key hashes the command arguments too (so the graph limit, or a changed format after an upgrade of
 * the bundle, give another key) and the state of the files it depends on, read BEFORE the command starts.
 * File state is mtime (1 second granularity on some file systems), size and inode: git replaces files
 * through a lock file and a rename, which changes the inode even within the same second.
 *
 * @internal
 */
final readonly class GitCacheKeys
{
    private const FORMAT = 1;

    /**
     * `git rev-parse --git-dir`: depends on the repository found from the project directory, i.e. on the
     * nearest `.git` (file or directory) of the project directory or of one of its parents.
     *
     * A `.git` file (linked worktree, submodule) holds the path of the git directory: its mtime, size and inode
     * are tracked. A `.git` directory always answers ".git" while it is a repository: its inode and its
     * structure are tracked, but not its mtime, which changes with every lock file git creates inside (a commit,
     * and every `git status` before git 2.15, which ignores GIT_OPTIONAL_LOCKS) and would make the key useless.
     *
     * @param list<string> $args
     */
    public function gitDirectory(array $args, string $workingDirectory): ?string
    {
        $directory = rtrim($workingDirectory, '/\\');

        for ($depth = 0; $depth < 64; ++$depth) {
            $entry = $directory . '/.git';

            if (is_file($entry)) {
                return $this->key('git-dir', $args, $workingDirectory, $entry, 'file', $this->stat($entry));
            }

            if (is_dir($entry)) {
                // What makes git take a directory for a repository.
                $structure = array_map(static fn (string $child): bool => file_exists($entry . '/' . $child), ['HEAD', 'objects', 'refs']);
                clearstatcache(true, $entry);

                return $this->key('git-dir', $args, $workingDirectory, $entry, 'directory', @fileinode($entry), $structure);
            }

            $parent = \dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return null;
    }

    /**
     * `git config --list`: the repository configuration, plus the global and system files, which can define
     * remotes too. Files pulled in with `include.path` are not tracked (a change there is not seen until
     * another tracked file changes or the cache is cleared).
     *
     * @param list<string> $args
     */
    public function config(array $args, string $commonDirectory): string
    {
        $files = [$commonDirectory . '/config', $commonDirectory . '/config.worktree', '/etc/gitconfig'];

        $home = getenv('HOME');
        if (\is_string($home) && $home !== '') {
            $files[] = $home . '/.gitconfig';
        }

        $xdg = getenv('XDG_CONFIG_HOME');
        $files[] = (\is_string($xdg) && $xdg !== '' ? $xdg : (\is_string($home) ? $home . '/.config' : '')) . '/git/config';

        return $this->key('config', $args, array_map($this->stat(...), $files));
    }

    /**
     * Output of a command that walks the history from HEAD (and the upstream): the same full object ids
     * give the same history, unless the repository is shallow, whose boundary is the `shallow` file.
     *
     * @param list<string> $args
     */
    public function history(array $args, string $commonDirectory, string $headOid, ?string $upstreamOid): string
    {
        return $this->key('history', $args, $headOid, $upstreamOid, $this->stat($commonDirectory . '/shallow'));
    }

    /**
     * `git describe --tags HEAD`: the history and every tag. Loose tags are listed one by one (path, mtime,
     * size, inode), nested ones included: creating a tag in a sub directory does not change the mtime of
     * `refs/tags` itself. Packed tags are tracked through the state of `packed-refs`.
     *
     * Null with the reftable ref backend: its tables are not tracked.
     *
     * @param list<string> $args
     */
    public function describe(array $args, string $commonDirectory, string $headOid): ?string
    {
        if (file_exists($commonDirectory . '/reftable')) {
            return null;
        }

        return $this->key(
            'describe',
            $args,
            $headOid,
            $this->stat($commonDirectory . '/shallow'),
            $this->stat($commonDirectory . '/packed-refs'),
            $this->looseTags($commonDirectory . '/refs/tags'),
        );
    }

    /**
     * @return list<string>
     */
    private function looseTags(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $tags = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $tags[] = $item->getPathname() . '|' . $this->stat($item->getPathname());
            }
        }
        sort($tags);

        return $tags;
    }

    private function stat(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat === false ? 'missing' : \sprintf('%d.%d.%d', $stat['mtime'], $stat['size'], $stat['ino']);
    }

    private function key(mixed ...$parts): string
    {
        // PSR-6 keys cannot contain "/", ":", "@" or braces: a hash is always valid.
        return 'gpb_' . hash('sha256', serialize([self::FORMAT, ...$parts]));
    }
}
