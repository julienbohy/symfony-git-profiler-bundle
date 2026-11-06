<?php

declare(strict_types=1);

use JulienBohy\GitProfilerBundle\DataCollector\GitDataCollector;
use JulienBohy\GitProfilerBundle\Git\Command\GitCommandRunnerInterface;
use JulienBohy\GitProfilerBundle\Git\Command\ProcessGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\Command\TraceableGitCommandRunner;
use JulienBohy\GitProfilerBundle\Git\GitRepository;
use JulienBohy\GitProfilerBundle\Git\GitRepositoryInterface;
use JulienBohy\GitProfilerBundle\Git\OperationDetector;
use JulienBohy\GitProfilerBundle\Git\Parser\DiffParser;
use JulienBohy\GitProfilerBundle\Git\Parser\LogParser;
use JulienBohy\GitProfilerBundle\Git\Parser\StatusParser;
use JulienBohy\GitProfilerBundle\Graph\CommitGraphLayout;
use JulienBohy\GitProfilerBundle\Graph\CommitGraphLayoutInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ProcessGitCommandRunner::class)
        ->args([param('kernel.project_dir'), param('git_profiler.git_binary'), param('git_profiler.timeout')]);

    $services->alias(GitCommandRunnerInterface::class, ProcessGitCommandRunner::class);

    $services->set(TraceableGitCommandRunner::class)
        ->decorate(ProcessGitCommandRunner::class)
        ->args([service('.inner')])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(StatusParser::class);
    $services->set(DiffParser::class);
    $services->set(LogParser::class);

    $services->set(OperationDetector::class)
        ->args([
            service(GitCommandRunnerInterface::class),
            param('kernel.project_dir'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'git_profiler']);

    $services->set(GitRepository::class)
        ->args([
            service(GitCommandRunnerInterface::class),
            service(StatusParser::class),
            service(DiffParser::class),
            service(LogParser::class),
            param('git_profiler.graph_commit_limit'),
            service('logger')->nullOnInvalid(),
            service(OperationDetector::class),
        ])
        ->tag('monolog.logger', ['channel' => 'git_profiler']);

    $services->alias(GitRepositoryInterface::class, GitRepository::class);

    $services->set(CommitGraphLayout::class);
    $services->alias(CommitGraphLayoutInterface::class, CommitGraphLayout::class);

    $services->set(GitDataCollector::class)
        ->args([
            service(GitRepositoryInterface::class),
            service(CommitGraphLayoutInterface::class),
            service(TraceableGitCommandRunner::class)->nullOnInvalid(),
        ])
        ->tag('data_collector', [
            'id' => 'git_profiler',
            'template' => '@GitProfiler/Collector/git.html.twig',
        ]);
};
