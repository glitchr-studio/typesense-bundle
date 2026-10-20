<?php

declare(strict_types=1);

namespace Typesense\Bundle\ORM;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
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

    public function __construct(TypesenseCollection $collection, ParameterBagInterface $parameterBag, ?CacheInterface $cache = null)
    {
        $this->isDebug = $parameterBag->get('kernel.debug');
        $this->collection = $collection;
        $this->cache = $cache ?? new Psr16Cache(new FilesystemAdapter('typesense', 0, $parameterBag->get('kernel.cache_dir')));
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
        $response->setHydratedHits($this->objectManager
            ->createQueryBuilder()
            ->select('e')
            ->from($this->metadata()->class, 'e')
            ->where('e.' . $primaryField->property . ' IN (:ids)')
            ->orderBy('FIELD(e.' . $primaryField->property . ', ' . implode(', ', $ids) . ')')
            ->setParameter('ids', $ids)
            ->setCacheable($cacheable)
            ->getQuery()
            ->useQueryCache($cacheable)
            ->setCacheRegion($classMetadata->cache['region'] ?? null)
            ->getResult());

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
