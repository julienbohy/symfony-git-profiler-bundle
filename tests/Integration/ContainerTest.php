<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests\Integration;

use JulienBohy\GitProfilerBundle\DataCollector\GitDataCollector;
use JulienBohy\GitProfilerBundle\Git\GitRepository;
use JulienBohy\GitProfilerBundle\Git\GitRepositoryInterface;
use JulienBohy\GitProfilerBundle\Tests\GitProfilerBundleConfigurationTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the real wiring of config/services.php (without booting the whole framework).
 */
final class ContainerTest extends TestCase
{
    public function testServicesAndDataCollectorTagAreRegistered(): void
    {
        $container = $this->loadContainer();

        self::assertTrue($container->hasDefinition(GitRepository::class));
        self::assertTrue($container->hasAlias(GitRepositoryInterface::class));
        self::assertTrue($container->hasDefinition(GitDataCollector::class));

        $tags = $container->getDefinition(GitDataCollector::class)->getTag('data_collector');
        self::assertCount(1, $tags);
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

    private function loadContainer(): ContainerBuilder
    {
        return GitProfilerBundleConfigurationTest::load();
    }
}
