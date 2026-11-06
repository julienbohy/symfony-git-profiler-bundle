<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Remote;

/**
 * Turns the URL of a git remote into the web URL of its repository.
 *
 * Handles `https://[user[:password]@]host[:port]/path[.git]`, `ssh://`, `git://` and the scp-like
 * `[user@]host:path[.git]`. Credentials, the ssh/git port, the `.git` suffix and trailing slashes are
 * dropped: the result is rebuilt from the host and the path only, so a password or token can never
 * reach the output. Anything unexpected (unknown host, odd characters, local path) gives null.
 *
 * @internal
 */
final class RemoteUrlParser
{
    private const URL = '#^(?<scheme>https?|ssh|git|git\+ssh|ssh\+git)://(?:[^/]*@)?(?<host>[A-Za-z0-9.-]+)(?::(?<port>\d*))?/(?<path>.+)$#';

    // "host:path"; "host://…" is a URL with an unsupported scheme, not an scp-like address.
    private const SCP = '#^(?:[^@/\s]+@)?(?<host>[A-Za-z0-9.-]+):(?<path>(?!//).+)$#';

    private const SEGMENT = '#^(?!\.{1,2}$)[\w.-]+$#';

    public function parse(string $url): ?RemoteRepository
    {
        $url = trim($url);

        if (preg_match(self::URL, $url, $matches) === 1) {
            // The port of an ssh:// or git:// URL is not the web port.
            $isHttp = str_starts_with($matches['scheme'], 'http');
            $scheme = $matches['scheme'] === 'http' ? 'http' : 'https';
            $port = $isHttp && $matches['port'] !== '' ? ':' . $matches['port'] : '';
        } elseif (preg_match(self::SCP, $url, $matches) === 1) {
            $scheme = 'https';
            $port = '';
        } else {
            return null;
        }

        $host = strtolower($matches['host']);
        $provider = GitProvider::fromHost($host);
        $path = $this->path($matches['path'], $provider);

        return $provider !== null && $path !== null ? new RemoteRepository($provider, \sprintf('%s://%s%s/%s', $scheme, $host, $port, $path)) : null;
    }

    /**
     * "owner/repo" (GitHub, Bitbucket) or "group/subgroup/repo" (GitLab), without ".git"; null when invalid.
     */
    private function path(string $path, ?GitProvider $provider): ?string
    {
        $path = trim($path, '/');
        $path = str_ends_with($path, '.git') ? substr($path, 0, -4) : $path;
        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                return null;
            }
        }

        $count = \count($segments);

        return ($provider === GitProvider::GitLab ? $count >= 2 : $count === 2) ? $path : null;
    }
}
