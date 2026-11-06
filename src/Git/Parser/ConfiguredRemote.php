<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Parser;

/**
 * A remote read from the git configuration. The URL may hold credentials: never display it as is.
 *
 * @internal
 */
final readonly class ConfiguredRemote
{
    public function __construct(
        public string $name,
        public string $url,
    ) {
    }

    /**
     * Branch name on the remote from a remote-tracking ref ("origin/feature/x" gives "feature/x"); null when the
     * ref does not belong to this remote.
     */
    public function branchOf(string $trackingRef): ?string
    {
        $prefix = $this->name . '/';
        if (!str_starts_with($trackingRef, $prefix)) {
            return null;
        }

        $branch = substr($trackingRef, \strlen($prefix));

        return $branch === '' ? null : $branch;
    }
}
