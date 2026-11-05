<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Exception;

/**
 * A git command could not be run, timed out, or exited with a non-zero status.
 */
final class GitCommandFailedException extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param list<string> $arguments
     */
    public static function fromArguments(array $arguments, string $reason, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('"git %s" failed: %s', implode(' ', $arguments), $reason), 0, $previous);
    }
}
