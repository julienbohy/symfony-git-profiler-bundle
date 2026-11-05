<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Command;

use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandTrace;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use PHPUnit\Framework\TestCase;

final class TraceableGitCommandRunnerTest extends TestCase
{
    public function testRecordsASuccessfulCommandAndReturnsItsOutput(): void
    {
        $runner = new TraceableGitCommandRunner($this->innerReturning("out\n"));

        self::assertSame("out\n", $runner->run(['status', '-z']));

        $traces = $runner->getTraces();
        self::assertCount(1, $traces);
        self::assertSame(['status', '-z'], $traces[0]->arguments);
        self::assertTrue($traces[0]->successful);
        self::assertNull($traces[0]->error);
        self::assertGreaterThanOrEqual(0.0, $traces[0]->durationMs);
    }

    public function testRecordsAFailureAndRethrowsTheSameException(): void
    {
        $failure = GitCommandFailedException::fromArguments(['log'], 'boom');
        $runner = new TraceableGitCommandRunner($this->innerThrowing($failure));

        try {
            $runner->run(['log']);
            self::fail('An exception was expected.');
        } catch (GitCommandFailedException $e) {
            self::assertSame($failure, $e);
        }

        $traces = $runner->getTraces();
        self::assertCount(1, $traces);
        self::assertFalse($traces[0]->successful);
        self::assertSame($failure->getMessage(), $traces[0]->error);
    }

    public function testResetClearsTheTraces(): void
    {
        $runner = new TraceableGitCommandRunner($this->innerReturning(''));
        $runner->run(['status']);

        $runner->reset();

        self::assertSame([], $runner->getTraces());
    }

    public function testCommandLineIsReadableAndCopyable(): void
    {
        $trace = new GitCommandTrace(['log', "--format=%H\x1f%s", 'my file', 'HEAD'], 1.0, true, null);

        self::assertSame("git log --format=%H%x1f%s 'my file' HEAD", $trace->commandLine());
    }

    private function innerReturning(string $output): GitCommandRunnerInterface
    {
        return new class($output) implements GitCommandRunnerInterface {
            public function __construct(private readonly string $output)
            {
            }

            public function run(array $args): string
            {
                return $this->output;
            }
        };
    }

    private function innerThrowing(\Throwable $failure): GitCommandRunnerInterface
    {
        return new class($failure) implements GitCommandRunnerInterface {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function run(array $args): string
            {
                throw $this->failure;
            }
        };
    }
}
