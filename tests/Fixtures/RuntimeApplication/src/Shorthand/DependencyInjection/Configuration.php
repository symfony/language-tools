<?php

namespace App\Shorthand\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('fixture_shorthand');
        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('storage')
                    ->beforeNormalization()
                        ->ifTrue(static fn ($value) => \is_array($value) && !\array_key_exists('pools', $value))
                        ->then(static function (array $value): array {
                            $pool = [];
                            foreach ($value as $key => $item) {
                                if ('default_pool' === $key) {
                                    continue;
                                }
                                $pool[$key] = $item;
                                unset($value[$key]);
                            }
                            if ([] !== $pool) {
                                $value['pools'] = ['default' => $pool];
                            }

                            return $value;
                        })
                    ->end()
                    ->children()
                        ->scalarNode('default_pool')->end()
                        ->arrayNode('pools')
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('dsn')->end()
                                    ->integerNode('size')->end()
                                    ->enumNode('mode')->values(['read', 'write'])->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('feature')->canBeEnabled()->end()
                ->arrayNode('exact_keys')
                    ->normalizeKeys(false)
                    ->children()
                        ->scalarNode('default-src')->end()
                        ->scalarNode('report-uri')->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
