<?php

declare(strict_types=1);

namespace Typesense\Bundle\ORM;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Typesense\Bundle\Exception\TypesenseException;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Query\Query;
use Typesense\Bundle\ORM\Query\Request;
use Typesense\Bundle\ORM\Query\Response;

class TypesenseFinder implements TypesenseFinderInterface
{
    protected ?int $cacheTTL = null;

    protected $cache;
    protected $cacheDir;

    protected $collection;
    protected $objectManager;
    protected $isDebug;

    /**
     * @param bool|object $debug whether an error's message is kept in the Response (true while
     *                           developing) - or, as before 2.0, a parameter bag (anything with
     *                           get()): its kernel.debug, and a cache in its kernel.cache_dir
     * @param ?CacheInterface $cache where cacheable responses are kept; in memory by default
     */
    public function __construct(TypesenseCollection $collection, bool|object $debug = false, ?CacheInterface $cache = null)
    {
        if (\is_object($debug)) {
            $cache ??= new Psr16Cache(new FilesystemAdapter('typesense', 0, $debug->get('kernel.cache_dir')));
            $debug = (bool) $debug->get('kernel.debug');
        }

        $this->isDebug = $debug;
        $this->collection = $collection;
        $this->cache = $cache ?? new Psr16Cache(new ArrayAdapter());
        $this->objectManager = $collection->metadata()->getObjectManager();
    }

    public function name(): string
    {
        return $this->metadata()->name;
    }

    public function metadata(): TypesenseMetadata
    {
        return $this->collection->metadata();
    }

    public function collection(): TypesenseCollection
    {
        return $this->collection;
    }

    /**
     * @param int|null $ttl
     * @return $this
     */
    /**
     * @param int|null $ttl
     * @return $this
     */
    public function cacheTTL(?int $ttl)
    {
        $this->cacheTTL = $ttl;

        return $this;
    }

    public function cache(): CacheInterface
    {
        return $this->cache;
    }

    public function raw(Request $request, bool $cacheable = false): Response
    {
        $key = [];
        $key[] = str_replace('\\', '__', static::class);
        $key[] = $this->collection->name();
        $key[] = sha1(serialize($request));
        $key = implode("_", $key);

        if ($cacheable && $this->cache->has($key)) {
            $response = $this->cache->get($key);
            if($response instanceof Response) return $response;
        }

        $response = $this->search($request);
        if ($cacheable && 200 == $response->getStatus()) {
            $this->cache->set($key, $response, $this->cacheTTL);
        }

        return $response;
    }

    public function query(Request $request, bool $cacheable = false): Response
    {
        return $this->hydrate($this->raw($request, $cacheable), $cacheable);
    }

    public function facet(string $facetBy, ?Query $query = null, array $checkbox = [], bool $sortByName = false, ?int $mode = Response::FACETS_USE_INDEX): mixed
    {
        if ($query) {
            $query = clone $query;
        }

        $query ??= new Query($facetBy);
        $query->facetBy($facetBy);

        $response = $this->search($query);
        return $response->getFacetCounts($checkbox, $sortByName, $mode);
    }

    private function hydrate(Response $response, bool $cacheable = false): Response
    {
        $ids = [];
        $primaryKey = $this->identifier();
        $primaryField = $this->metadata()->fields[$primaryKey];
        foreach ($response->getResults() ?? [] as $result) {
            $ids[] = $result['document'][$primaryKey];
        }

        if (!count($ids)) {
            return $response;
        }

        $classMetadata = $this->objectManager->getClassMetadata($this->collection->metadata()->class);
        $query = $this->objectManager
            ->createQueryBuilder()
            ->select('e')
            ->from($this->metadata()->class, 'e')
            ->where('e.' . $primaryField->property . ' IN (:ids)')
            ->setParameter('ids', $ids)
            ->setCacheable($cacheable)
            ->getQuery()
            ->useQueryCache($cacheable);
        // An entity without a second-level cache region has none to name
        // (setCacheRegion(null) is a TypeError on doctrine/orm 3).
        if (null !== ($region = $classMetadata->cache['region'] ?? null)) {
            $query->setCacheRegion($region);
        }
        $entities = $query->getResult();

        // In the order of the hits - the database returns an IN in its own
        // order; this used to be MySQL's FIELD(), and is the same on any
        // database now.
        $position = array_flip(array_map('strval', $ids));
        $identifier = $primaryField->property;
        usort($entities, fn ($a, $b) => ($position[(string) $classMetadata->getFieldValue($a, $identifier)] ?? PHP_INT_MAX) <=> ($position[(string) $classMetadata->getFieldValue($b, $identifier)] ?? PHP_INT_MAX));
        $response->setHydratedHits($entities);

        return $response->setHydrated(true);
    }

    /**
     * @param Query $query
     * @return Response
     */
    private function search(Query $query)
    {
        $query = $this->addQueryByDiscriminatorMap(clone $query);
        try {
            // dump($this->collection->search($query), $query);
            // exit(1);
            return new Response($this->collection->search($query));
        } catch (TypesenseException $e) {
            return new Response([], $e->getCode(), $this->isDebug ? [Response::MESSAGE => $e->getMessage()] : []);
        }
    }

    private function addQueryByDiscriminatorMap(Query $query): Query
    {
        $classMetadata = $this->objectManager->getClassMetadata($this->collection->metadata()->class);
        if (!$classMetadata->discriminatorColumn && $query->getHeader(Query::INSTANCE_OF)) {
            throw new \LogicException('Class "' . $this->collection->metadata()->class . "\" doesn't have discriminator values");
        }

        $classNames = array_filter(
            explode(',', $query->getHeader(Query::INSTANCE_OF) ?? ''),
            fn($c) => !empty(trim($c ?? ''))
        );

        foreach ($classNames as $className) {

            $relation = str_starts_with(trim($className), '^') ? ':!=' : ':=';

            $classMetadata = $this->objectManager->getClassMetadata(trim($className, ' ^'));
            $query->addFilterBy($classMetadata->discriminatorColumn['name'] . $relation . $classMetadata->discriminatorValue);

            foreach ($classMetadata->subClasses as $subClassName) {
                $classMetadata = $this->objectManager->getClassMetadata($subClassName);
                $query->addFilterBy($classMetadata->discriminatorColumn['name'] . $relation . $classMetadata->discriminatorValue);
            }
        }

        return $query;
    }

    private function identifier(): string
    {
        foreach ($this->collection->metadata()->fields as $name => $field) {
            if ($field->identifier ?? false) {
                return $name;
            }
        }

        throw new TypesenseException(sprintf('No identifier key found in collection %s', $this->collection->metadata()->name), 500);
    }
}
