<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Command;

use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;

/**
 * Outcome of one command of a parallel batch: its output or its failure, and its own duration.
 *
 * @internal
 */
final readonly class GitCommandResult
{
    private function __construct(
        private ?string $output,
        public ?GitCommandFailedException $failure,
        public float $durationMs,
        public bool $cached,
    ) {
    }

    public static function success(string $output, float $durationMs): self
    {
        return new self($output, null, $durationMs, false);
    }

    public static function failure(GitCommandFailedException $failure, float $durationMs): self
    {
        return new self(null, $failure, $durationMs, false);
    }

    /**
     * The output of an earlier run, read from the cache: no process was started.
     */
    public static function cached(string $output): self
    {
        return new self($output, null, 0.0, true);
    }

    public function isSuccessful(): bool
    {
        return $this->failure === null;
    }

    /**
     * @throws GitCommandFailedException when the command failed
     */
    public function output(): string
    {
        return $this->failure !== null ? throw $this->failure : (string) $this->output;
    }
}
