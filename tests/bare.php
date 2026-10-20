<?php

/*
 * The package in plain PHP: no bundle, no container, no kernel. A Doctrine
 * EntityManager on SQLite, the mapping read from the attributes of
 * Fixtures\Attribute\Book, the manager, a connection, a collection and its
 * finder built by hand, the indexer on Doctrine's EventManager. With a
 * Typesense server (TYPESENSE_URL, TYPESENSE_KEY): the collection created,
 * three books flushed and so indexed, searched, faceted, one removed, the
 * collection dropped. Without one: a book converted to its document.
 * Prints what happened and every class and file PHP loaded, as JSON.
 *
 *   php tests/bare.php
 */

use Doctrine\ORM\Events;
use Typesense\Bundle\DBAL\Connection;
use Typesense\Bundle\EventListener\TypesenseIndexer;
use Typesense\Bundle\ORM\Mapping\AttributeReader;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Query\Query;
use Typesense\Bundle\ORM\Query\Response;
use Typesense\Bundle\ORM\Transformer\EntityTransformer;
use Typesense\Bundle\ORM\TypesenseFinder;
use Typesense\Bundle\ORM\TypesenseManager;
use Typesense\Bundle\Tests\Fixtures\Attribute\Book;
use Typesense\Bundle\Tests\Fixtures\Attribute\Tag;
use Typesense\Bundle\Tests\Fixtures\EntityManagers;

$loader = require getenv('TYPESENSE_BUNDLE_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('Typesense\\Bundle\\Tests\\', __DIR__);

$report = [];
$entityManager = EntityManagers::sqlite();

$url = parse_url(getenv('TYPESENSE_URL') ?: 'http://localhost:8108');
$connection = new Connection('default', [
    'secret' => getenv('TYPESENSE_KEY') ?: 'xyz',
    'scheme' => $url['scheme'] ?? 'http',
    'host' => $url['host'] ?? 'localhost',
    'port' => $url['port'] ?? 8108,
    'options' => ['connection_timeout_seconds' => 2],
]);
try {
    $online = !in_array('--offline', $argv, true) && $connection->getHealth();
} catch (\Exception) {
    $online = false;
}

[$name, $mapping] = AttributeReader::read(Book::class);
$prefix = 'bare_' . bin2hex(random_bytes(4)) . '_';
$transformer = new EntityTransformer($entityManager);
$collection = new TypesenseCollection(new TypesenseMetadata($name, $mapping, $transformer, $prefix), $connection);
$manager = (new TypesenseManager('default'))->addConnection($connection)->addCollection($collection);
$manager->addFinder($finder = new TypesenseFinder($collection));
$entityManager->getEventManager()->addEventListener([Events::postPersist, Events::postUpdate, Events::preRemove, Events::postRemove, Events::postFlush], $indexer = new TypesenseIndexer($manager, function (\Throwable $e) use (&$report) { $report['errors'][] = $e->getMessage(); }));

$report['online'] = $online;
$report['collection'] = $collection->metadata()->getCollectionName();
$report['schema'] = array_map(fn ($f) => $f->toArray(), $collection->metadata()->getConfiguration()['fields']);
$report['document'] = $transformer->convert(new Book('Dune', 3, new Tag('Science fiction', 'sf')));

if ($online) {
    try {
        $collection->create();
        $sf = new Tag('Science fiction', 'sf');
        $entityManager->persist($dune = new Book('Dune', 3, $sf));
        $entityManager->persist(new Book('Dune Messiah', 2, $sf));
        $entityManager->persist(new Book('Emma', 1, new Tag('Novel', 'novel')));
        $entityManager->flush();
        $entityManager->clear();

        $response = $finder->query((new Query('title', 'dune'))->sortBy('rank:asc')->facetBy('tags'));
        $report['found'] = $response->getFound();
        $report['hydrated'] = array_map(fn (Book $b) => $b->getTitle(), $response->getResults());
        $report['facets'] = $response->getFacetCounts([], true, Response::FACETS_USE_KEY);
        $report['facet'] = $finder->facet('tags', null, ['tags' => ['Novel']], true, Response::FACETS_USE_KEY);
        $report['infix'] = $finder->raw((new Query('title', 'essi'))->numTypos(0)->infix(Query::INFIX_ALWAYS))->getFound();

        $entityManager->remove($entityManager->find(Book::class, $dune->getId()));
        $entityManager->flush();
        $report['after_remove'] = $finder->raw(new Query('title', 'dune'))->getFound();
    } finally {
        $collection->delete();
    }
}

$report['symbols'] = [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()];
$report['files'] = get_included_files();

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
