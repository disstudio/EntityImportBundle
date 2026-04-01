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
                ->scalarNode('source_connection')->cannotBeEmpty()->defaultValue('default')->end()
                ->scalarNode('target_connection')->cannotBeEmpty()->defaultValue('default')->end()
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
                            ->scalarNode('source_table')->isRequired()->end()
                            ->scalarNode('source_identifier')->isRequired()->end()
                            ->scalarNode('target_identifier')->defaultNull()->end()
                            ->scalarNode('target_entity')->isRequired()->end()
                            ->scalarNode('factory')->defaultNull()->end()
                            ->arrayNode('foreign_keys')
                                ->normalizeKeys(false)
                                ->useAttributeAsKey('name')
                                ->beforeNormalization()
                                    ->always()
                                    ->then(function ($v) {
                                        if (!is_array($v)) {
                                            return $v;
                                        }
                                        foreach ($v as $key => $value) {
                                            if (is_string($value)) {
                                                $v[$key] = [
                                                    'target_key' => $value,
                                                    'local_identifier' => $key,
                                                ];
                                            } elseif (is_array($value) && !isset($value['local_identifier'])) {
                                                $v[$key]['local_identifier'] = $key;
                                            }
                                        }
                                        return $v;
                                    })
                                ->end()
                                ->arrayPrototype()
                                    ->children()
                                        ->scalarNode('local_identifier')->isRequired()->cannotBeEmpty()->end()
                                        ->scalarNode('target_key')->isRequired()->cannotBeEmpty()->end()
                                        ->scalarNode('join_table')->defaultNull()->end()
                                        ->scalarNode('foreign_identifier')->defaultNull()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->integerNode('chunk_size')->defaultValue(100)->end()
            ->end();

        return $treeBuilder;
    }
}
