<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Typesense\Bundle\ORM\TypesenseManager;
use Typesense\Bundle\ORM\TypesenseManagerInterface;
use Typesense\Bundle\DBAL\Connection;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\TypesenseFinder;
use Typesense\Bundle\EventListener\TypesenseIndexer;
use Typesense\Bundle\Bridge\Symfony\Command\CreateCommand;
use Typesense\Bundle\Bridge\Symfony\Command\ListCommand;
use Typesense\Bundle\Bridge\Symfony\Command\HealthCommand;
use Typesense\Bundle\Bridge\Symfony\Command\ActionCommand;
use Typesense\Bundle\Bridge\Symfony\Command\UpdateCommand;
use Typesense\Bundle\ORM\Transformer\Abstract\AbstractTransformer;
use Typesense\Bundle\ORM\Transformer\EntityTransformer;
use Typesense\Bundle\Bridge\Symfony\IndexingWarning;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
            ->public();

    # Aliases
    $services->alias('typesense_manager', TypesenseManager::class);
    $services->alias(TypesenseManagerInterface::class, 'typesense_manager');

    # Typesense Manager
    $services->set(TypesenseManager::class)
        ->public()
        ->args([
            abstract_arg('Manager configuration'),
        ]);

    # Abstract connection
    $services->set('typesense.connection', Connection::class)
        ->abstract()
        ->public()
        ->args([
            abstract_arg('Connection name'),
            abstract_arg('Connection parameters'),
        ]);

    # The finders' responses, cleared by the indexer
    $services->set('typesense.cache.adapter', FilesystemAdapter::class)
        ->private()
        ->args(['typesense', 0, '%kernel.cache_dir%']);
    $services->set('typesense.cache', Psr16Cache::class)
        ->args([service('typesense.cache.adapter')]);

    # Metadata definition
    $services->set('typesense.metadata', TypesenseMetadata::class)
        ->abstract()
        ->public()
        ->args([
            abstract_arg('Index name'),
            abstract_arg('Entity class'),
            service('typesense.transformer.entity'),
            '', # Collection prefix
        ]);

    # Collection definition
    $services->set('typesense.collection', TypesenseCollection::class)
        ->abstract()
        ->public()
        ->args([
            abstract_arg('Metadata service'),
            abstract_arg('Collection name'),
        ]);

    # Finder definition
    $services->set('typesense.finder', TypesenseFinder::class)
        ->abstract()
        ->args([
            abstract_arg('Collection service'),
            '%kernel.debug%',
            service('typesense.cache'),
        ]);

    # Doctrine event listener
    $services->set('typesense.listener.doctrine_indexer', TypesenseIndexer::class)
        ->tag('doctrine.event_listener', ['event' => 'postPersist', 'priority' => -1, 'connection' => 'default'])
        ->tag('doctrine.event_listener', ['event' => 'postUpdate',  'priority' => -1, 'connection' => 'default'])
        ->tag('doctrine.event_listener', ['event' => 'preRemove',   'priority' => -1, 'connection' => 'default'])
        ->tag('doctrine.event_listener', ['event' => 'postRemove',  'priority' => -1, 'connection' => 'default'])
        ->tag('doctrine.event_listener', ['event' => 'postFlush',   'priority' => -1, 'connection' => 'default'])
        ->args([
            service('typesense_manager'),
            service('typesense.indexing_warning'),
            service('typesense.cache'),
        ]);

    # A failure to index, as a warning in the session's flash bag
    $services->set('typesense.indexing_warning', IndexingWarning::class)
        ->private()
        ->args([service('request_stack')->nullOnInvalid()]);

    # Console commands
    $services->set('typesense.command.create', CreateCommand::class)
        ->tag('console.command')
        ->args([service('typesense_manager')]);

    $services->set('typesense.command.list', ListCommand::class)
        ->tag('console.command')
        ->args([service('typesense_manager')]);

    $services->set('typesense.command.health', HealthCommand::class)
        ->tag('console.command')
        ->args([service('typesense_manager')]);

    $services->set('typesense.command.action', ActionCommand::class)
        ->tag('console.command')
        ->args([service('typesense_manager')]);

    $services->set('typesense.command.update', UpdateCommand::class)
        ->tag('console.command')
        ->args([service('typesense_manager')]);

    # Transformers
    $services->set('typesense.transformer', AbstractTransformer::class)
        ->abstract()
        ->public();

    $services->set('typesense.transformer.entity', EntityTransformer::class)
        ->parent('typesense.transformer')
        ->public()
        ->args([
            service('doctrine.orm.entity_manager'),
        ]);
};