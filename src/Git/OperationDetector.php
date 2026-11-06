<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git;

use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Detects the operation left in progress (rebase, merge...) from the files git keeps in its directory.
 *
 * The git directory is located with a single `git rev-parse --git-dir` (`--absolute-git-dir` needs git 2.13),
 * which also covers linked worktrees (`.git/worktrees/<name>`) and submodules. The resolved path is kept for
 * the lifetime of the process.
 */
final class OperationDetector
{
    private ?string $gitDirectory = null;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly GitCommandRunnerInterface $git,
        private readonly string $workingDirectory,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Never throws: any failure means "no operation".
     */
    public function detect(): ?Operation
    {
        try {
            return $this->detectIn($this->gitDirectory());
        } catch (\Throwable $e) {
            $this->logger->debug('Git operation state unavailable: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return null;
        }
    }

    private function gitDirectory(): string
    {
        if ($this->gitDirectory !== null) {
            return $this->gitDirectory;
        }

        $path = trim($this->git->run(['rev-parse', '--git-dir']));
        if ($path === '') {
            throw new \UnexpectedValueException('git rev-parse --git-dir returned nothing.');
        }

        // Relative to the working directory of the git process (".git" at the root of a repository).
        $isAbsolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
        $directory = $isAbsolute ? $path : rtrim($this->workingDirectory, '/\\') . '/' . $path;

        if (!is_dir($directory)) {
            throw new \UnexpectedValueException(\sprintf('Git directory "%s" does not exist.', $directory));
        }

        return $this->gitDirectory = $directory;
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
