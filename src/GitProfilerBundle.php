<?php

declare(strict_types=1);

namespace JulienBohy\GitProfilerBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Process\ExecutableFinder;

final class GitProfilerBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('git_binary')
                    ->info('Path to the git executable; null looks "git" up in the PATH.')
                    ->defaultNull()
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => null !== $v && !\is_string($v))
                        ->thenInvalid('The "git_binary" option must be a string or null, got %s.')
                    ->end()
                ->end()
                ->floatNode('timeout')
                    ->info('Maximum duration of each git command, in seconds.')
                    ->min(0.1)
                    ->defaultValue(5.0)
                ->end()
                ->integerNode('graph_commit_limit')
                    ->info('Number of commits drawn in the commit graph.')
                    ->min(1)
                    ->defaultValue(30)
                ->end()
            ->end();
    }

    /**
     * The timeout is passed through untouched: it may be an %env()% placeholder, resolved at run time
     * (a build-time cast would turn it into 0). ProcessGitCommandRunner casts and sanitises it.
     *
     * @param array{git_binary: ?string, timeout: float|int|string, graph_commit_limit: int} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('git_profiler.git_binary', $config['git_binary'] ?? (new ExecutableFinder())->find('git') ?? 'git')
            ->set('git_profiler.timeout', $config['timeout'])
            ->set('git_profiler.graph_commit_limit', $config['graph_commit_limit']);

        $container->import('../config/services.php');
    }
}
