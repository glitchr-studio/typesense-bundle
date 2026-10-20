<?php

declare(strict_types=1);

namespace Typesense\Bundle\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    private TreeBuilder $treeBuilder;

    public function getTreeBuilder(): TreeBuilder
    {
        return $this->treeBuilder;
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $this->treeBuilder = new TreeBuilder('typesense');
        $this->treeBuilder->getRootNode()
            ->children()
            ->scalarNode('default_connection')
            ->info('id to load (can be set later)')
            ->defaultValue('default')
            ->end()
            ->scalarNode('collection_prefix')
            ->info('Put in front of every collection name on the server (one per environment sharing a server)')
            ->defaultValue('')
            ->end()
            ->arrayNode('connections')
            ->info('Typesense server information')
            ->arrayPrototype()
            ->children()
            ->scalarNode('secret')->defaultNull()->end()
            ->scalarNode('url')->defaultValue(null)->end()
            ->scalarNode('scheme')->defaultValue('http')->end()
            ->scalarNode('host')->defaultValue('localhost')->end()
            ->scalarNode('port')->defaultValue(8108)->end()
            ->scalarNode('path')->defaultValue(null)->end()
            ->arrayNode('options')
            ->defaultValue(['connection_timeout_seconds' => 5])
            ->scalarPrototype()->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->variableNode('attributes')
            ->info('Directories whose classes declare their collection by attributes (Typesense\\Bundle\\Attribute\\Collection): the application\'s src/ when null, none when empty')
            ->defaultNull()
            ->end()
            ->arrayNode('mappings')
            ->info('Mapping definitions')
            ->useAttributeAsKey('name')
            ->arrayPrototype()
            ->children()
            ->scalarNode('connection')->end()
            ->scalarNode('class')->end()
            ->arrayNode('fields')
            ->arrayPrototype()
            ->children()
            ->scalarNode('type')->end()
            ->scalarNode('property')->end()
            ->booleanNode('facet')->end()
            ->booleanNode('infix')->end()
            ->arrayNode('options')
            ->info('Further parameters of the field\'s schema, sent as they are (sort, optional, locale...)')
            ->variablePrototype()->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->scalarNode('default_sorting_field')->defaultValue(null)->end()
            ->arrayNode('token_separators')
            ->defaultValue(['+', '-', '@', '.', ' '])
            ->scalarPrototype()->end()
            ->end()
            ->arrayNode('symbols_to_index')
            ->defaultValue(['+'])
            ->scalarPrototype()->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end();

        return $this->treeBuilder;
    }
}
