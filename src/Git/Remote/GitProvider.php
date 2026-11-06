<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Remote;

/**
 * A hosting service whose web URLs are known.
 */
enum GitProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';

    /**
     * Unknown hosts give null: no URL is ever guessed.
     *
     * @param string $host lower-case host name
     */
    public static function fromHost(string $host): ?self
    {
        return match (true) {
            $host === 'github.com' => self::GitHub,
            $host === 'bitbucket.org' => self::Bitbucket,
            // Covers gitlab.com and self-hosted instances (gitlab.example.com).
            str_contains($host, 'gitlab') => self::GitLab,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
        };
    }

    /**
     * Path of a commit page, relative to the repository's web URL.
     */
    public function commitPath(string $hash): string
    {
        return match ($this) {
            self::GitHub => '/commit/' . $hash,
            self::GitLab => '/-/commit/' . $hash,
            self::Bitbucket => '/commits/' . $hash,
        };
    }

    /**
     * @param string $encodedBranch branch name, already URL-encoded
     */
    public function branchPath(string $encodedBranch): string
    {
        return match ($this) {
            self::GitHub => '/tree/' . $encodedBranch,
            self::GitLab => '/-/tree/' . $encodedBranch,
            self::Bitbucket => '/branch/' . $encodedBranch,
        };
    }

    /**
     * Path of the page comparing a commit with a branch; null when the provider has no reliable pattern.
     *
     * @param string $encodedBranch branch name, already URL-encoded
     */
    public function comparePath(string $hash, string $encodedBranch): ?string
    {
        return match ($this) {
            self::GitHub => '/compare/' . $hash . '...' . $encodedBranch,
            self::GitLab => '/-/compare/' . $hash . '...' . $encodedBranch,
            self::Bitbucket => null,
        };
    }
}
