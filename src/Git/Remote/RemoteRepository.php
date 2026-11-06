<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Remote;

/**
 * A repository on a known hosting service, immutable. The web URL never holds credentials.
 */
final readonly class RemoteRepository
{
    /**
     * @param string $webUrl web base URL, no trailing slash, e.g. "https://github.com/owner/repo"
     */
    public function __construct(
        public GitProvider $provider,
        public string $webUrl,
    ) {
    }

    /**
     * @param string $hash full hash of a commit that exists on the remote
     */
    public function commitUrl(string $hash): string
    {
        return $this->webUrl . $this->provider->commitPath($hash);
    }

    /**
     * @param string $branch branch name on the remote (slashes are kept)
     */
    public function branchUrl(string $branch): string
    {
        return $this->webUrl . $this->provider->branchPath($this->encode($branch));
    }

    /**
     * @param string $hash   full hash of a commit that exists on the remote
     * @param string $branch branch name on the remote (slashes are kept)
     */
    public function compareUrl(string $hash, string $branch): ?string
    {
        $path = $this->provider->comparePath($hash, $this->encode($branch));

        return $path !== null ? $this->webUrl . $path : null;
    }

    private function encode(string $branch): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $branch)));
    }
}
