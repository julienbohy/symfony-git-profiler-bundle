<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Command;

use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandResult;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandTrace;
use JulienBohy\GitProfilerBundle\Git\Command\ParallelGitCommandRunnerInterface;
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

    public function testABatchIsTracedWithTheDurationOfEachProcessInTheOrderOfTheCommands(): void
    {
        $failure = GitCommandFailedException::fromArguments(['log'], 'boom');
        $inner = new class([GitCommandResult::success('a', 12.5), GitCommandResult::failure($failure, 3.0), GitCommandResult::success('c', 7.25)]) implements ParallelGitCommandRunnerInterface {
            /**
             * @param list<GitCommandResult> $results
             */
            public function __construct(private readonly array $results)
            {
            }

            public function run(array $args): string
            {
                return '';
            }

            public function runParallel(array $commands): array
            {
                return $this->results;
            }
        };
        $runner = new TraceableGitCommandRunner($inner);

        $results = $runner->runParallel([['show'], ['log'], ['config']]);

        self::assertCount(3, $results);
        self::assertSame('a', $results[0]->output());
        $traces = $runner->getTraces();
        self::assertCount(3, $traces);
        self::assertSame([['show'], ['log'], ['config']], array_map(static fn (GitCommandTrace $trace): array => $trace->arguments, $traces));
        self::assertSame([12.5, 3.0, 7.25], array_map(static fn (GitCommandTrace $trace): float => $trace->durationMs, $traces));
        self::assertSame([true, false, true], array_map(static fn (GitCommandTrace $trace): bool => $trace->successful, $traces));
        self::assertSame($failure->getMessage(), $traces[1]->error);
        self::assertNull($traces[0]->error);
        self::assertSame([false, false, false], array_map(static fn (GitCommandTrace $trace): bool => $trace->cached, $traces));
    }

    public function testABatchOverARunnerWithoutTheParallelCapabilityIsTracedOneByOne(): void
    {
        $failure = GitCommandFailedException::fromArguments(['log'], 'boom');
        $runner = new TraceableGitCommandRunner($this->innerThrowing($failure));

        $results = $runner->runParallel([['log'], ['log']]);

        self::assertCount(2, $results);
        self::assertSame($failure, $results[0]->failure);
        self::assertSame($failure, $results[1]->failure);
        $traces = $runner->getTraces();
        self::assertCount(2, $traces);
        self::assertFalse($traces[0]->successful);
        self::assertGreaterThanOrEqual(0.0, $results[1]->durationMs);

        $ok = new TraceableGitCommandRunner($this->innerReturning('out'));
        $results = $ok->runParallel([['status'], ['show']]);
        self::assertSame('out', $results[0]->output());
        self::assertSame(['status'], $ok->getTraces()[0]->arguments);
        self::assertTrue($ok->getTraces()[1]->successful);
    }

    public function testRecordsACachedCommandWithoutDuration(): void
    {
        $runner = new TraceableGitCommandRunner($this->innerReturning(''));

        $runner->recordCached(['log', '-n', '30']);
        $runner->run(['status']);

        $traces = $runner->getTraces();
        self::assertCount(2, $traces);
        self::assertSame(['log', '-n', '30'], $traces[0]->arguments);
        self::assertTrue($traces[0]->cached);
        self::assertTrue($traces[0]->successful);
        self::assertNull($traces[0]->error);
        self::assertSame(0.0, $traces[0]->durationMs);
        self::assertFalse($traces[1]->cached);
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
