<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Fixtures;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * A Doctrine EntityManager on an in-memory SQLite database, mapping the
 * attribute fixtures, its schema created - Doctrine alone, no framework.
 */
final class EntityManagers
{
    public static function sqlite(): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Attribute'], true);
        if (\PHP_VERSION_ID >= 80400 && method_exists($configuration, 'enableNativeLazyObjects')) {
            $configuration->enableNativeLazyObjects(true);
        }
        $entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $configuration);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }
}
