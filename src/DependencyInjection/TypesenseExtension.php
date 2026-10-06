<?php

declare(strict_types=1);

namespace Typesense\Bundle\DependencyInjection;

use LogicException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Typesense\Bundle\ORM\TypesenseManager;

class TypesenseExtension extends Extension
{
    private string $defaultConnection;
    private string $collectionPrefix;

    public function load(array $configs, ContainerBuilder $container): void
    {
        // Format XML
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');

        // Configuration file: ./config/package/base.yaml
        $processor = new Processor();
        $configuration = new Configuration();
        $typesense = $processor->processConfiguration($configuration, $configs);

        $this->setConfiguration($container, $typesense, $configuration->getTreeBuilder()->getRootNode()->getNode()->getName());

        $this->defaultConnection = $typesense['default_connection'] ?? 'default';
        $this->collectionPrefix = (string) ($typesense['collection_prefix'] ?? '');
        $this->initialize($container);

        foreach ($typesense['connections'] ?? [] as $connectionName => $configuration) {
            $this->loadConnection($connectionName, $configuration, $container);
        }

        foreach ($typesense['mappings'] ?? [] as $collectionName => $configuration) {

            if(str_contains($collectionName,"__")) {
                throw new LogicException("Collection name cannot contains `__`");
            }

            $this->loadMetadata($collectionName, $configuration ?? [], $container);
            $this->loadCollections($collectionName, $configuration ?? [], $container);
            $this->loadFinders($collectionName, $container);
        }
    }

    /**
     * @param ContainerBuilder $container
     * @param array $config
     * @param $globalKey
     * @return $this
     */
    /**
     * @param ContainerBuilder $container
     * @param array $config
     * @param $globalKey
     * @return $this
     */
    public function setConfiguration(ContainerBuilder $container, array $config, $globalKey = '')
    {
        foreach ($config as $key => $value) {
            if (!empty($globalKey)) {
                $key = $globalKey . '.' . $key;
            }

            if (is_array($value)) {
                $this->setConfiguration($container, $value, $key);
            } else {
                $container->setParameter($key, $value);
            }
        }

        return $this;
    }

    /**
     * @param ContainerBuilder $container
     * @return $this
     */
    /**
     * @param ContainerBuilder $container
     * @return $this
     */
    public function initialize(ContainerBuilder $container)
    {
        $definition = $container->getDefinition(TypesenseManager::class);
        $definition->replaceArgument(0, $this->defaultConnection);

        $container->setDefinition(TypesenseManager::class, $definition);

        return $this;
    }

    /**
     * Loads the configured connections.
     *
     * @param ContainerBuilder $container A ContainerBuilder instance
     */
    private function loadConnection(string $connectionName, array $connection, ContainerBuilder $container): void
    {
        $id = sprintf('typesense.connection.%s', $connectionName);
        $definition = new ChildDefinition('typesense.connection');
        $definition->replaceArgument(0, $connectionName);

        $container->setDefinition($id, $definition);
        $definition->addTag('typesense.connection');
    }

    /**
     * Loads the configured collection.
     *
     * @param string $name
     * @param array $collection
     * @param ContainerBuilder $container A ContainerBuilder instance
     *
     */
    private function loadMetadata(string $name, array $collection, ContainerBuilder $container): void
    {
        $id = sprintf('typesense.metadata.%s', $name);
        $definition = new ChildDefinition('typesense.metadata');
        $definition->replaceArgument(0, $name);
        $definition->replaceArgument(1, $collection);
        $definition->replaceArgument(3, $this->collectionPrefix);

        $container->setDefinition($id, $definition);
        $definition->addTag('typesense.metadata');
    }

    /**
     * Loads the configured collection.
     *
     * @param string $name
     * @param array $collection
     * @param ContainerBuilder $container A ContainerBuilder instance
     *
     */
    private function loadCollections(string $name, array $collection, ContainerBuilder $container): void
    {
        $connectionName = $collection['connection'] ?? $this->defaultConnection;

        $id = sprintf('typesense.collection.%s', $name);
        $definition = new ChildDefinition('typesense.collection');
        $definition->replaceArgument(0, new Reference(sprintf('typesense.metadata.%s', $name)));
        $definition->replaceArgument(1, new Reference(sprintf('typesense.connection.%s', $connectionName)));

        $container->setDefinition($id, $definition);
        $definition->addTag('typesense.collection');
    }

    /**
     * Loads the configured index finders.
     */
    private function loadFinders(string $name, ContainerBuilder $container): void
    {
        $id = sprintf('typesense.finder.%s', $name);
        $definition = new ChildDefinition('typesense.finder');
        $definition->replaceArgument(0, new Reference(sprintf('typesense.collection.%s', $name)));

        $definition->addTag('typesense.finder');
        $container->setDefinition($id, $definition);
    }
}
