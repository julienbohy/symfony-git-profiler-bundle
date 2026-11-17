<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Command;

use JulienBohy\GitProfilerBundle\Exception\GitCommandFailedException;
use JulienBohy\GitProfilerBundle\Git\Cache\GitOutputCache;
use JulienBohy\GitProfilerBundle\Git\Command\GitBatch;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandResult;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Command\ParallelGitCommandRunnerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GitBatchTest extends TestCase
{
    public function testAllTheCommandsAreSubmittedInOneCallBeforeAnyResultIsRead(): void
    {
        $runner = new RecordingBatchRunner();
        $batch = new GitBatch($runner, new GitOutputCache());
        $batch->add('a', ['show']);
        $batch->add('b', ['log']);
        $batch->add('c', ['config']);

        $results = $batch->run();

        self::assertSame([[['show'], ['log'], ['config']]], $runner->batches);
        self::assertSame([], $runner->singles);
        self::assertSame(['a', 'b', 'c'], array_keys($results));
        self::assertSame('out:show', $results['a']->output());
    }

    public function testARunnerWithoutTheParallelCapabilityRunsTheCommandsOneByOne(): void
    {
        $runner = new class implements GitCommandRunnerInterface {
            /**
             * @var list<string>
             */
            public array $calls = [];

            public function run(array $args): string
            {
                $this->calls[] = $args[0];

                return $args[0] === 'log' ? throw GitCommandFailedException::fromArguments($args, 'boom') : 'out:' . $args[0];
            }
        };
        $batch = new GitBatch($runner, new GitOutputCache());
        $batch->add('a', ['show']);
        $batch->add('b', ['log']);
        $batch->add('c', ['config']);

        $results = $batch->run();

        self::assertSame(['show', 'log', 'config'], $runner->calls);
        self::assertSame('out:show', $results['a']->output());
        self::assertFalse($results['b']->isSuccessful());
        self::assertSame('out:config', $results['c']->output());
        self::assertGreaterThanOrEqual(0.0, $results['a']->durationMs);
    }

    public function testWhatIsStoredCanBeReducedAndTheLiveOutputStaysWhole(): void
    {
        $cache = new GitOutputCache(new ArrayAdapter());
        $batch = new GitBatch(new RecordingBatchRunner(), $cache);
        $batch->add('a', ['config'], 'key', static fn (string $output): string => strtoupper($output));

        $results = $batch->run();
        $batch->storeSuccesses();

        self::assertSame('out:config', $results['a']->output());
        self::assertSame('OUT:CONFIG', $cache->lookup('key', ['config']));
    }

    public function testACachedCommandIsNotRun(): void
    {
        $cache = new GitOutputCache(new ArrayAdapter());
        $cache->store('key-b', 'cached log');
        $runner = new RecordingBatchRunner();
        $batch = new GitBatch($runner, $cache);
        $batch->add('a', ['show'], null);
        $batch->add('b', ['log'], 'key-b');

        $results = $batch->run();

        self::assertSame([[['show']]], $runner->batches);
        self::assertSame('cached log', $results['b']->output());
        self::assertTrue($results['b']->cached);
        self::assertFalse($results['a']->cached);
    }

    public function testNoProcessIsStartedWhenEverythingIsCached(): void
    {
        $cache = new GitOutputCache(new ArrayAdapter());
        $cache->store('key', 'cached');
        $runner = new RecordingBatchRunner();
        $batch = new GitBatch($runner, $cache);
        $batch->add('a', ['log'], 'key');

        $batch->run();

        self::assertSame([], $runner->batches);
    }

    public function testOnlySuccessfulKeyedCommandsAreStoredAndOnlyOnRequest(): void
    {
        $cache = new GitOutputCache(new ArrayAdapter());
        $runner = new RecordingBatchRunner(failing: ['log']);
        $batch = new GitBatch($runner, $cache);
        $batch->add('show', ['show']); // no key: never cached
        $batch->add('graph', ['log'], 'key-graph'); // fails
        $batch->add('config', ['config'], 'key-config');

        $batch->run();
        self::assertNull($cache->lookup('key-config', ['config'])); // not before storeSuccesses()

        $batch->storeSuccesses();

        self::assertSame('out:config', $cache->lookup('key-config', ['config']));
        self::assertNull($cache->lookup('key-graph', ['log']));
    }

    public function testAFailureIsReportedInItsOwnResult(): void
    {
        $batch = new GitBatch(new RecordingBatchRunner(failing: ['log']), new GitOutputCache());
        $batch->add('a', ['show']);
        $batch->add('b', ['log']);

        $results = $batch->run();

        self::assertSame('out:show', $results['a']->output());
        self::assertFalse($results['b']->isSuccessful());
    }

    public function testARunnerReturningTooFewResultsIsAnError(): void
    {
        $runner = new class implements ParallelGitCommandRunnerInterface {
            public function run(array $args): string
            {
                return '';
            }

            public function runParallel(array $commands): array
            {
                return [];
            }
        };
        $batch = new GitBatch($runner, new GitOutputCache());
        $batch->add('a', ['show']);

        $this->expectException(\LogicException::class);

        $batch->run();
    }
}

/**
 * @internal
 */
final class RecordingBatchRunner implements ParallelGitCommandRunnerInterface
{
    /**
     * @var list<list<list<string>>>
     */
    public array $batches = [];

    /**
     * @var list<list<string>>
     */
    public array $singles = [];

    /**
     * @param list<string> $failing first arguments of the commands that fail
     */
    public function __construct(private readonly array $failing = [])
    {
    }

    public function run(array $args): string
    {
        $this->singles[] = $args;

        return 'out:' . $args[0];
    }

    public function runParallel(array $commands): array
    {
        $this->batches[] = $commands;

        return array_map(
            fn (array $args): GitCommandResult => \in_array($args[0], $this->failing, true)
                ? GitCommandResult::failure(GitCommandFailedException::fromArguments($args, 'simulated'), 1.0)
                : GitCommandResult::success('out:' . $args[0], 1.0),
            $commands,
        );
    }
}
