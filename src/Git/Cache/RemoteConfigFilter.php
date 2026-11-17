<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Cache;

/**
 * Reduces `git config --null --list` to what the remote resolution reads, before it is written to the cache:
 * the `branch.<name>.remote` and `remote.<name>.url` entries, in the same "key\nvalue\0" format (so the
 * parser reads them exactly as it reads the full output), with the userinfo (`user[:password]@`) removed from
 * the URLs. The rest of the configuration (user name and email, `http.*` settings, credential helpers, tokens)
 * never reaches the disk. The resolved links do not change: the URL normaliser discards the userinfo anyway.
 *
 * @internal
 */
final readonly class RemoteConfigFilter
{
    public function filter(string $output): string
    {
        $kept = '';

        foreach (explode("\0", $output) as $entry) {
            [$key, $value] = explode("\n", $entry, 2) + [1 => ''];

            if (preg_match('/^branch\..+\.remote$/', $key) === 1) {
                $kept .= $key . "\n" . $value . "\0";
            } elseif (preg_match('/^remote\..+\.url$/', $key) === 1) {
                $kept .= $key . "\n" . $this->withoutUserinfo($value) . "\0";
            }
        }

        return $kept;
    }

    private function withoutUserinfo(string $url): string
    {
        // "scheme://userinfo@host/...": the userinfo is what precedes the first "@" before the first "/".
        $stripped = preg_replace('#^([A-Za-z][A-Za-z0-9+.-]*://)[^/]*@#', '$1', $url, 1, $count);
        if ($count > 0) {
            return (string) $stripped;
        }

        // scp-like "user@host:path".
        return (string) preg_replace('#^[^@/\s]+@#', '', $url, 1);
    }
}
