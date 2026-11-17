<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Command;

/**
 * One git command of the request: executed, or served from the cache (no process was started).
 *
 * @internal
 */
final readonly class GitCommandTrace
{
    /**
     * @param list<string> $arguments
     */
    public function __construct(
        public array $arguments,
        public float $durationMs,
        public bool $successful,
        public ?string $error,
        public bool $cached = false,
    ) {
    }

    /**
     * The command as a developer could type it: the ASCII unit separator used in
     * log formats is shown as git's own "%x1f" escape, arguments with whitespace
     * or quotes are single-quoted.
     */
    public function commandLine(): string
    {
        $parts = array_map(
            static function (string $argument): string {
                $argument = str_replace("\x1f", '%x1f', $argument);

                return preg_match('/[\s\'"]/', $argument) === 1 ? escapeshellarg($argument) : $argument;
            },
            $this->arguments,
        );

        return trim('git ' . implode(' ', $parts));
    }
}
