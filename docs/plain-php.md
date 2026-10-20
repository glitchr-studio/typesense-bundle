# In plain PHP

Everything but `Bridge/Symfony` (and `TypesenseBundle`) is plain PHP on
Doctrine and the Typesense client: no container, no kernel, no
configuration file. `tests/bare.php` is the whole script; in short:

```php
use Doctrine\ORM\Events;
use Typesense\Bundle\DBAL\Connection;
use Typesense\Bundle\EventListener\TypesenseIndexer;
use Typesense\Bundle\ORM\Mapping\AttributeReader;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Query\Query;
use Typesense\Bundle\ORM\Transformer\EntityTransformer;
use Typesense\Bundle\ORM\TypesenseFinder;
use Typesense\Bundle\ORM\TypesenseManager;

// $entityManager: any Doctrine EntityManager
$connection = new Connection('default', ['url' => 'http://localhost:8108', 'secret' => 'xyz']);
$transformer = new EntityTransformer($entityManager);

[$name, $mapping] = AttributeReader::read(Book::class);       // or the same array written by hand
$collection = new TypesenseCollection(new TypesenseMetadata($name, $mapping, $transformer), $connection);

$manager = (new TypesenseManager('default'))->addConnection($connection)->addCollection($collection);
$manager->addFinder($finder = new TypesenseFinder($collection));   // debug: false, an in-memory cache

// the indexing rule on Doctrine's events; a failure to index goes to the callable
$entityManager->getEventManager()->addEventListener(
    [Events::postPersist, Events::postUpdate, Events::preRemove, Events::postRemove, Events::postFlush],
    new TypesenseIndexer($manager, fn (\Throwable $e) => error_log($e->getMessage())),
);

$collection->create();
$entityManager->persist(new Book('Dune', 3));
$entityManager->flush();                                  // indexed

$books = $finder->query((new Query('title', 'dune'))->sortBy('rank:asc'))->getResults();   // entities, in the hits' order
```

`AttributeReader::scan([$directory, ...])` gives the mappings of every class
declared there with `#[Collection]`, by key.

`TypesenseFinder` takes `(collection, bool $debug = false, ?CacheInterface $cache = null)`,
`TypesenseIndexer` `(manager, ?callable $onError = null, ?CacheInterface $cache = null)` -
pass the finders' cache to the indexer to have it cleared after indexing.
