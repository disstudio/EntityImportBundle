<?php

namespace Disstudio\EntityImport\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('disstudio_entity_import');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('entity_map')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->beforeNormalization()
                            ->ifNull()
                            ->then(function () {
                                return [];
                            })
                        ->end()
                        ->children()
                            ->scalarNode('legacyTable')
                                ->isRequired()
                            ->end()
                            ->scalarNode('legacyIdentifier')
                                ->isRequired()
                            ->end()
                            ->scalarNode('targetIdentifier')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('targetEntity')
                                ->isRequired()
                            ->end()
                            ->scalarNode('factory')
                                ->defaultNull()
                            ->end()
                            ->arrayNode('foreignKeys')
                                ->scalarPrototype()->end()
                                ->defaultValue([])
                            ->end()
                        ->end()
                    ->end()
                ->end() // entity_map
                ->scalarNode('chunk_size')
                    ->defaultValue(100)
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
