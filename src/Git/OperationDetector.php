<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Detects the operation left in progress (rebase, merge...) from the files git keeps in its directory
 * (located by {@see GitDirectory}, shared with the other readers of that directory).
 */
final class OperationDetector
{
    private readonly GitDirectory $directory;

    private readonly LoggerInterface $logger;

    public function __construct(
        GitCommandRunnerInterface $git,
        string $workingDirectory,
        ?LoggerInterface $logger = null,
        ?GitDirectory $gitDirectory = null,
    ) {
        $this->directory = $gitDirectory ?? new GitDirectory($git, $workingDirectory);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Never throws: any failure means "no operation".
     */
    public function detect(): ?Operation
    {
        try {
            return $this->detectIn($this->directory->path());
        } catch (\Throwable $e) {
            $this->logger->debug('Git operation state unavailable: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return null;
        }
    }

    private function detectIn(string $gitDirectory): ?Operation
    {
        $read = fn (string $file): ?string => $this->firstLine($gitDirectory . '/' . $file);

        if (is_dir($gitDirectory . '/rebase-merge')) {
            return $this->rebase($gitDirectory . '/rebase-merge', 'msgnum', 'end');
        }

        if (is_dir($gitDirectory . '/rebase-apply')) {
            if (is_file($gitDirectory . '/rebase-apply/applying')) {
                return new Operation(
                    OperationKind::ApplyMailbox,
                    $this->integer($read('rebase-apply/next')),
                    $this->integer($read('rebase-apply/last')),
                );
            }

            return $this->rebase($gitDirectory . '/rebase-apply', 'next', 'last');
        }

        if (is_file($gitDirectory . '/MERGE_HEAD')) {
            $message = $read('MERGE_MSG');

            return new Operation(OperationKind::Merge, target: $message ?? $this->shorten($read('MERGE_HEAD')));
        }

        if (is_file($gitDirectory . '/CHERRY_PICK_HEAD')) {
            return new Operation(OperationKind::CherryPick, target: $this->shorten($read('CHERRY_PICK_HEAD')));
        }

        if (is_file($gitDirectory . '/REVERT_HEAD')) {
            return new Operation(OperationKind::Revert, target: $this->shorten($read('REVERT_HEAD')));
        }

        if (is_file($gitDirectory . '/BISECT_LOG')) {
            return new Operation(OperationKind::Bisect);
        }

        return null;
    }

    private function rebase(string $directory, string $stepFile, string $totalFile): Operation
    {
        $headName = $this->firstLine($directory . '/head-name');
        $prefix = 'refs/heads/';

        return new Operation(
            OperationKind::Rebase,
            $this->integer($this->firstLine($directory . '/' . $stepFile)),
            $this->integer($this->firstLine($directory . '/' . $totalFile)),
            $headName !== null && str_starts_with($headName, $prefix) ? substr($headName, \strlen($prefix)) : null, // "detached HEAD" otherwise
            $this->shorten($this->firstLine($directory . '/onto')),
        );
    }

    /**
     * First line of a file, null when it is missing, unreadable or empty.
     */
    private function firstLine(string $file): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $line = trim(explode("\n", $content, 2)[0]);

        return $line !== '' ? $line : null;
    }

    private function integer(?string $value): ?int
    {
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Shortens full object ids only; anything else (a ref name, a message) is kept as git wrote it.
     */
    private function shorten(?string $value): ?string
    {
        return $value !== null && preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/', $value) === 1 ? substr($value, 0, 7) : $value;
    }
}
