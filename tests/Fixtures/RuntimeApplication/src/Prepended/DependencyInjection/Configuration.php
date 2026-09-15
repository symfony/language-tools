<?php

namespace App\Prepended\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('fixture_prepended');
        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('base_uri')->end()
                ->arrayNode('cache')
                    ->children()
                        ->integerNode('max_age')->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
