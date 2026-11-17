<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Integration;

use JulienBohy\GitProfilerBundle\DataCollector\GitDataCollector;
use JulienBohy\GitProfilerBundle\Git\GitRepository;
use JulienBohy\GitProfilerBundle\Git\GitRepositoryInterface;
use JulienBohy\GitProfilerBundle\Tests\GitProfilerBundleConfigurationTest;
use JulienBohy\GitProfilerBundle\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the real wiring of config/services.php (without booting the whole framework).
 */
final class ContainerTest extends TestCase
{
    use TemporaryDirectory;

    public function testServicesAndDataCollectorTagAreRegistered(): void
    {
        $container = $this->loadContainer();

        self::assertTrue($container->hasDefinition(GitRepository::class));
        self::assertTrue($container->hasAlias(GitRepositoryInterface::class));
        self::assertTrue($container->hasDefinition(GitDataCollector::class));

        $tags = $container->getDefinition(GitDataCollector::class)->getTag('data_collector');
        self::assertCount(1, $tags);
        self::assertIsArray($tags[0]);
        self::assertSame('git_profiler', $tags[0]['id']);
        self::assertSame('@GitProfiler/Collector/git.html.twig', $tags[0]['template']);
    }

    public function testCollectorIsWiredAndReadsTheRepository(): void
    {
        // The bundle root is a Git repository: the collector must read info from it.
        $container = $this->loadContainer();
        $container->getDefinition(GitDataCollector::class)->setPublic(true);
        $container->compile();

        $collector = $container->get(GitDataCollector::class);
        self::assertInstanceOf(GitDataCollector::class, $collector);

        $collector->collect(new Request(), new Response());
        $collector->lateCollect();

        self::assertTrue($collector->isAvailable());
        self::assertNotSame('', (string) $collector->getBranch());
        self::assertMatchesRegularExpression('/^[0-9a-f]{7,}$/', (string) $collector->getShortCommit());

        // The git state of the bundle repository is not controlled: we only check
        // types and the absence of exceptions, not precise values.
        self::assertIsInt($collector->getChangedFilesCount());
        self::assertIsInt($collector->getUnpushedCommitsCount());
        self::assertIsBool($collector->hasUpstream());
        self::assertIsArray($collector->getWorkingFiles());
        self::assertIsArray($collector->getUnpushedCommits());
        self::assertIsArray($collector->getUnpushedFiles());
    }

    public function testTheGitOutputsAreCachedInTheKernelCacheDirectoryAcrossRequests(): void
    {
        $cacheDir = $this->createTemporaryDirectory('gpb_container_');

        try {
            $first = $this->collect($cacheDir);
            $second = $this->collect($cacheDir);

            self::assertDirectoryExists($cacheDir . '/git_profiler');
            self::assertSame(0, $first->getCachedCommandsCount());
            // A new container is another request: what was cached by the first one is served to the second.
            self::assertGreaterThan(0, $second->getCachedCommandsCount());
            self::assertSame($first->getBranch(), $second->getBranch());
            self::assertSame($first->getShortCommit(), $second->getShortCommit());
        } finally {
            $this->removeDirectory($cacheDir);
        }
    }

    public function testWithoutAKernelCacheDirectoryTheCollectorStillWorksWithoutCache(): void
    {
        $first = $this->collect(null);
        $second = $this->collect(null);

        self::assertTrue($second->isAvailable());
        self::assertSame(0, $first->getCachedCommandsCount());
        self::assertSame(0, $second->getCachedCommandsCount());
        self::assertNotEmpty($second->getCommands());
    }

    private function collect(?string $cacheDir): GitDataCollector
    {
        $container = GitProfilerBundleConfigurationTest::load(parameters: $cacheDir !== null ? ['kernel.cache_dir' => $cacheDir] : []);
        $container->getDefinition(GitDataCollector::class)->setPublic(true);
        $container->compile();

        $collector = $container->get(GitDataCollector::class);
        self::assertInstanceOf(GitDataCollector::class, $collector);
        $collector->collect(new Request(), new Response());
        $collector->lateCollect();

        return $collector;
    }

    private function loadContainer(): ContainerBuilder
    {
        return GitProfilerBundleConfigurationTest::load();
    }
}
