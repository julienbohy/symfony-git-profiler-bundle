<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle\Tests;

use JulienBohy\GitProfilerBundle\DataCollector\GitDataCollector;
use JulienBohy\GitProfilerBundle\Git\Cache\GitOutputCache;
use JulienBohy\GitProfilerBundle\Git\Command\ProcessGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\GitDirectory;
use JulienBohy\GitProfilerBundle\Git\GitRepository;
use JulienBohy\GitProfilerBundle\GitProfilerBundle;
use JulienBohy\GitProfilerBundle\Graph\CommitGraphLayoutInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

final class GitProfilerBundleConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = self::load();

        self::assertNotSame('', $container->getParameter('git_profiler.git_binary'));
        self::assertSame(5.0, $container->getParameter('git_profiler.timeout'));
        self::assertSame(30, $container->getParameter('git_profiler.graph_commit_limit'));
    }

    public function testExplicitValues(): void
    {
        $container = self::load(['git_binary' => '/opt/git/bin/git', 'timeout' => 2, 'graph_commit_limit' => 10]);

        self::assertSame('/opt/git/bin/git', $container->getParameter('git_profiler.git_binary'));
        self::assertEquals(2.0, $container->getParameter('git_profiler.timeout'));
        self::assertSame(10, $container->getParameter('git_profiler.graph_commit_limit'));
    }

    public function testRejectsANonPositiveTimeout(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::load(['timeout' => 0]);
    }

    public function testAnEnvPlaceholderTimeoutIsKeptForRuntimeResolution(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', \dirname(__DIR__));
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.debug', true);
        $extension = (new GitProfilerBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('git_profiler', ['timeout' => '%env(float:GIT_PROFILER_TEST_TIMEOUT)%']);

        // The same pass the kernel runs while building the container.
        (new MergeExtensionConfigurationPass())->process($container);

        // Not cast to 0.0 at build time: the placeholder survives and resolves when the container runs.
        $timeout = $container->getParameter('git_profiler.timeout');
        self::assertIsString($timeout);

        $_ENV['GIT_PROFILER_TEST_TIMEOUT'] = '2.5';
        try {
            self::assertSame(2.5, $container->resolveEnvPlaceholders($container->getParameterBag()->resolveValue($timeout), true));
        } finally {
            unset($_ENV['GIT_PROFILER_TEST_TIMEOUT']);
        }
    }

    #[DataProvider('invalidGitBinaries')]
    public function testRejectsAnInvalidGitBinary(mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::load(['git_binary' => $value]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidGitBinaries(): iterable
    {
        yield 'empty string' => [''];
        yield 'true' => [true];
        yield 'integer' => [123];
        yield 'array' => [['git']];
    }

    public function testRejectsAGraphLimitBelowOne(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::load(['graph_commit_limit' => 0]);
    }

    public function testTheRunnerIsDecoratedByTheResettableTracer(): void
    {
        $container = self::load();

        $tracer = $container->getDefinition(TraceableGitCommandRunner::class);
        self::assertSame(ProcessGitCommandRunner::class, $tracer->getDecoratedService()[0] ?? null);
        self::assertSame([['method' => 'reset']], $tracer->getTag('kernel.reset'));
    }

    public function testTheRepositoryLogsOnItsOwnChannel(): void
    {
        self::assertSame(
            [['channel' => 'git_profiler']],
            self::load()->getDefinition(GitRepository::class)->getTag('monolog.logger'),
        );
    }

    public function testTheCollectorReceivesTheLayoutAndTheTracer(): void
    {
        $arguments = self::load()->getDefinition(GitDataCollector::class)->getArguments();

        self::assertInstanceOf(Reference::class, $arguments[1]);
        self::assertInstanceOf(Reference::class, $arguments[2]);
        self::assertSame(CommitGraphLayoutInterface::class, (string) $arguments[1]);
        self::assertSame(TraceableGitCommandRunner::class, (string) $arguments[2]);
    }

    public function testTheOutputsAreCachedInADedicatedPoolInsideTheKernelCacheDirectory(): void
    {
        $container = self::load(parameters: ['kernel.cache_dir' => '/var/www/var/cache/dev']);

        $pool = $container->getDefinition('git_profiler.cache');
        self::assertSame(FilesystemAdapter::class, $pool->getClass());
        self::assertSame(['git_profiler', 0, '%kernel.cache_dir%/git_profiler'], $pool->getArguments());
        self::assertFalse($pool->isPublic());

        $services = [GitOutputCache::class, GitDirectory::class, GitRepository::class];
        $cacheArguments = $container->getDefinition(GitOutputCache::class)->getArguments();
        self::assertInstanceOf(Reference::class, $cacheArguments[0]);
        self::assertSame('git_profiler.cache', (string) $cacheArguments[0]);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $cacheArguments[0]->getInvalidBehavior());
        foreach ($services as $service) {
            self::assertTrue($container->hasDefinition($service));
        }
    }

    public function testThereIsNoPoolWithoutAKernelCacheDirectory(): void
    {
        $container = self::load();

        self::assertFalse($container->hasDefinition('git_profiler.cache'));
        $pool = $container->getDefinition(GitOutputCache::class)->getArgument(0);
        self::assertInstanceOf(Reference::class, $pool);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $pool->getInvalidBehavior());
    }

    public function testTheServicesThatNeedTheCacheShareTheSameOne(): void
    {
        $container = self::load();

        $directory = $container->getDefinition(GitDirectory::class)->getArguments();
        $repository = $container->getDefinition(GitRepository::class)->getArguments();

        self::assertInstanceOf(Reference::class, $directory[2]);
        self::assertSame(GitOutputCache::class, (string) $directory[2]);
        self::assertInstanceOf(Reference::class, $repository[12]);
        self::assertSame(GitOutputCache::class, (string) $repository[12]);
        self::assertInstanceOf(Reference::class, $repository[11]);
        self::assertSame(GitDirectory::class, (string) $repository[11]);
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $parameters extra kernel parameters
     */
    public static function load(array $config = [], array $parameters = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach ($parameters as $name => $value) {
            $container->setParameter($name, $value);
        }
        $container->setParameter('kernel.project_dir', \dirname(__DIR__));
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.debug', true);

        (new GitProfilerBundle())->getContainerExtension()?->load([$config], $container);

        return $container;
    }
}
