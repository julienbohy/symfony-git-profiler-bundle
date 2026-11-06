<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Git\Command;

use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Runs git through symfony/process (Adapter).
 *
 * @internal
 */
final readonly class ProcessGitCommandRunner implements GitCommandRunnerInterface
{
    /**
     * Stable, parseable output; never take optional locks (the profiler must not
     * compete with the developer's own git commands for .git/index.lock); never prompt.
     */
    private const ENVIRONMENT = [
        'LC_ALL' => 'C',
        'GIT_OPTIONAL_LOCKS' => '0',
        'GIT_TERMINAL_PROMPT' => '0',
        // false removes the variable from the child's environment: when the app runs from a git hook
        // or script, these inherited variables would redirect every command to another repository.
        'GIT_DIR' => false,
        'GIT_WORK_TREE' => false,
        'GIT_INDEX_FILE' => false,
        'GIT_COMMON_DIR' => false,
        'GIT_PREFIX' => false,
    ];

    private const DEFAULT_TIMEOUT = 5.0;

    /**
     * Neutralise user config that alters the output we parse: log.showSignature
     * prints signature checks to stdout (and verifies each commit), diff.relative
     * makes diff paths relative to the working directory instead of the repository root.
     */
    private const CONFIG_OVERRIDES = ['-c', 'log.showSignature=false', '-c', 'diff.relative=false'];

    private float $timeout;

    /**
     * @param float|int|string $timeout seconds; may arrive as a string when it comes from an %env()% placeholder.
     *                                  Symfony Process treats 0 as "no timeout", which would let a hung git block
     *                                  the worker forever, so any value that is not strictly positive falls back
     *                                  to the default.
     */
    public function __construct(
        private string $workingDirectory,
        private string $gitBinary = 'git',
        float|int|string $timeout = self::DEFAULT_TIMEOUT,
    ) {
        $timeout = (float) $timeout;
        $this->timeout = $timeout > 0.0 ? $timeout : self::DEFAULT_TIMEOUT;
    }

    public function run(array $args): string
    {
        $process = $this->createProcess($args);

        try {
            $process->run();
        } catch (ProcessExceptionInterface $e) {
            throw GitCommandFailedException::fromArguments($args, $e->getMessage(), $e);
        }

        if (!$process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            throw GitCommandFailedException::fromArguments($args, $stderr !== '' ? $stderr : \sprintf('exit code %d', (int) $process->getExitCode()));
        }

        return $process->getOutput();
    }

    /**
     * @internal exposed for tests only
     *
     * @param list<string> $args
     */
    public function createProcess(array $args): Process
    {
        return new Process([$this->gitBinary, ...self::CONFIG_OVERRIDES, ...$args], $this->workingDirectory, self::ENVIRONMENT, null, $this->timeout);
    }
}
