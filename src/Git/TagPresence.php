<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

use JulienBohy\GitProfilerBundle\Git\Parser\PackedRefsParser;

/**
 * Tells, without running git, whether `git describe --tags` can possibly succeed: it fails on a repository
 * without any tag, which would show up as a failed command on every request.
 *
 * Tags are loose files under `refs/tags/` (names may contain slashes) or lines of `packed-refs`, both in the
 * common git directory. The answer is fail-safe: anything unusual (reftable ref format, unreadable file,
 * unlocatable directory) means "might have tags", so git decides.
 */
final readonly class TagPresence
{
    public function __construct(
        private GitDirectory $gitDirectory,
        private PackedRefsParser $parser = new PackedRefsParser(),
    ) {
    }

    /**
     * False only when it is certain that the repository has no tag. Never throws.
     */
    public function mightHaveTags(): bool
    {
        try {
            $common = $this->gitDirectory->commonPath();

            return is_dir($common . '/reftable') || $this->hasLooseTag($common . '/refs/tags') || $this->hasPackedTag($common . '/packed-refs');
        } catch (\Throwable) {
            return true;
        }
    }

    private function hasLooseTag(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        // Directories may stay behind after a tag such as "v1/x" is deleted: only files count.
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read line by line, stopping at the first tag: the file can be large.
     */
    private function hasPackedTag(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Cannot read "%s".', $file));
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if ($this->parser->isTagLine($line)) {
                    return true;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }
}
