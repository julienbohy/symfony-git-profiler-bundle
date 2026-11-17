<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Git\Cache;

use JulienBohy\GitProfilerBundle\Git\Cache\GitOutputCache;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandResult;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Command\ParallelGitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GitOutputCacheTest extends TestCase
{
    public function testWithoutAPoolNothingIsCached(): void
    {
        $cache = new GitOutputCache();

        $cache->store('key', 'output');

        self::assertFalse($cache->isEnabled());
        self::assertNull($cache->lookup('key', ['log']));
    }

    public function testStoresAndReturnsTheRawOutput(): void
    {
        $cache = new GitOutputCache(new ArrayAdapter());

        self::assertTrue($cache->isEnabled());
        self::assertNull($cache->lookup('key', ['log']));

        $cache->store('key', "line\0two\n");

        self::assertSame("line\0two\n", $cache->lookup('key', ['log']));
    }

    public function testAnEmptyOutputIsAHitNotAMiss(): void
    {
        $cache = new GitOutputCache(new ArrayAdapter());
        $cache->store('key', '');

        self::assertSame('', $cache->lookup('key', ['log']));
    }

    public function testAHitIsTracedAsACachedCommand(): void
    {
        $tracer = new TraceableGitCommandRunner($this->innerRunner());
        $cache = new GitOutputCache(new ArrayAdapter(), $tracer);
        $cache->store('key', 'output');

        self::assertNull($cache->lookup('missing', ['log', 'a']));
        $afterMiss = $tracer->getTraces();

        self::assertSame('output', $cache->lookup('key', ['log', 'b']));
        self::assertSame([], $afterMiss);

        $traces = $tracer->getTraces();
        self::assertCount(1, $traces);
        self::assertSame(['log', 'b'], $traces[0]->arguments);
        self::assertTrue($traces[0]->cached);
        self::assertTrue($traces[0]->successful);
        self::assertSame(0.0, $traces[0]->durationMs);
    }

    public function testAMissIsNotTraced(): void
    {
        $tracer = new TraceableGitCommandRunner($this->innerRunner());

        (new GitOutputCache(new ArrayAdapter(), $tracer))->lookup('key', ['log']);

        self::assertSame([], $tracer->getTraces());
    }

    public function testAFailingPoolIsAMissAndNeverThrows(): void
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new \RuntimeException('down'));
        $cache = new GitOutputCache($pool);

        $cache->store('key', 'output');

        self::assertNull($cache->lookup('key', ['log']));
    }

    public function testAnEntryThatIsNotAStringIsAMiss(): void
    {
        $pool = new ArrayAdapter();
        $pool->save($pool->getItem('key')->set(['not', 'a string']));

        self::assertNull((new GitOutputCache($pool))->lookup('key', ['log']));
    }

    private function innerRunner(): GitCommandRunnerInterface
    {
        return new class implements ParallelGitCommandRunnerInterface {
            public function run(array $args): string
            {
                return '';
            }

            public function runParallel(array $commands): array
            {
                return array_map(static fn (): GitCommandResult => GitCommandResult::success('', 0.0), $commands);
            }
        };
    }
}
