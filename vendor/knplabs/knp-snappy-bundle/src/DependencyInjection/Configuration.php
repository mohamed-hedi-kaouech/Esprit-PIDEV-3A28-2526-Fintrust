<?php

namespace Knp\Bundle\SnappyBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('knp_snappy');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('temporary_folder')->defaultNull()->end()
                ->integerNode('process_timeout')->defaultNull()->end()
                ->append($this->createEngineNode('pdf', 'wkhtmltopdf'))
                ->append($this->createEngineNode('image', 'wkhtmltoimage'))
            ->end()
        ;

        return $treeBuilder;
    }

    private function createEngineNode(string $name, string $defaultBinary): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('binary')->defaultValue($defaultBinary)->end()
                ->arrayNode('options')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('name')
                    ->prototype('variable')->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('env')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('name')
                    ->prototype('scalar')->end()
                    ->defaultValue([])
                ->end()
            ->end()
        ;

        return $node;
    }
}
