<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Parser;

/**
 * Finds the remote of a branch in `git config --null --list`: "key\nvalue\0" entries (a key without value
 * has no newline). Section and variable names are lower-cased by git, subsection names (branch, remote) are not.
 *
 * @internal
 */
final class GitConfigParser
{
    public const DEFAULT_REMOTE = 'origin';

    /**
     * The remote configured for the branch (`branch.<branch>.remote`), `origin` without one. Null when that
     * remote has no URL. When a remote has several URLs the first one is used, as `git remote get-url` does.
     */
    public function remote(string $output, string $branch): ?ConfiguredRemote
    {
        $branchRemote = null;
        $urls = [];

        foreach (explode("\0", $output) as $entry) {
            [$key, $value] = explode("\n", $entry, 2) + [1 => ''];

            if (preg_match('/^branch\.(?<branch>.+)\.remote$/', $key, $matches) === 1) {
                if ($matches['branch'] === $branch) {
                    $branchRemote = $value;
                }
            } elseif (preg_match('/^remote\.(?<name>.+)\.url$/', $key, $matches) === 1) {
                $urls[$matches['name']] ??= $value;
            }
        }

        $name = $branchRemote ?? self::DEFAULT_REMOTE;

        return isset($urls[$name]) ? new ConfiguredRemote($name, $urls[$name]) : null;
    }
}
