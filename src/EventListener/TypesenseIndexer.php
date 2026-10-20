<?php

declare(strict_types=1);

namespace Typesense\Bundle\EventListener;

use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Http\Client\Exception\NetworkException;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Typesense\Bundle\DBAL\Transaction;
use Typesense\Bundle\Exception\TypesenseException;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\TypesenseManager;

/**
 * The indexing rule, on Doctrine's events: an object of a mapped class is
 * upserted into each collection that supports it once it is persisted or
 * updated, and deleted from them once it is removed, all of it when the
 * unit of work is flushed; then the finders' cached responses are cleared.
 *
 * Any Doctrine EventManager can carry it (Symfony's doctrine-bundle does
 * it through the bundle's services; by hand: $eventManager->addEventListener
 * ([...the five events], $indexer)). A failure to reach the server does not
 * stop the flush: it is handed to $onError, once per flush - Symfony's bridge
 * puts it in the session's flash bag as a warning.
 */
class TypesenseIndexer
{
    protected array $transactions = [];

    protected array $objectIds = [];
    protected $typesenseManager;
    protected $cache;

    /** @var ?\Closure(\Throwable): void */
    protected ?\Closure $onError;

    /**
     * @param ?callable|object     $onError what to do with a failure to index (once per flush) -
     *                                      or, as before 2.0, Symfony's request stack: a warning
     *                                      in the current session's flash bag
     * @param CacheInterface|object|null $cache the finders' cache, cleared after indexing - or, as
     *                                          before 2.0, a parameter bag: the cache in its
     *                                          kernel.cache_dir
     */
    public function __construct(TypesenseManager $typesenseManager, mixed $onError = null, mixed $cache = null, ?CacheInterface $finderCache = null)
    {
        $this->typesenseManager = $typesenseManager;

        if (\is_object($onError) && method_exists($onError, 'getCurrentRequest')) {
            $requestStack = $onError;
            $onError = static function (\Throwable $e) use ($requestStack): void {
                $request = $requestStack->getCurrentRequest();
                $session = $request?->hasSession() ? $request->getSession() : null;
                if ($session && method_exists($session, 'getFlashBag')) {
                    $session->getFlashBag()->add('warning', 'Typesense ' . $e->getCode() . ': ' . $e->getMessage());
                }
            };
        }
        $this->onError = null !== $onError ? \Closure::fromCallable($onError) : null;

        if (!$cache instanceof CacheInterface && \is_object($cache) && method_exists($cache, 'get')) {
            $cache = $finderCache ?? new Psr16Cache(new FilesystemAdapter('typesense', 0, $cache->get('kernel.cache_dir')));
        }
        $this->cache = $cache instanceof CacheInterface ? $cache : ($finderCache ?? new Psr16Cache(new ArrayAdapter()));
    }

    /**
     * @return list<TypesenseCollection> the collections an object is indexed in
     */
    protected function collectionsOf(object $object): array
    {
        return array_values(array_filter($this->typesenseManager->getCollections(), fn (TypesenseCollection $collection) => $collection->supports($object)));
    }

    public function postPersist(LifecycleEventArgs $args)
    {
        $object = $args->getObject();
        foreach ($this->collectionsOf($object) as $collection) {
            $this->transactions[] = new Transaction($collection, Transaction::PERSIST, $object);
        }
    }

    public function postUpdate(LifecycleEventArgs $args)
    {
        $object = $args->getObject();
        foreach ($this->collectionsOf($object) as $collection) {
            $this->transactions[] = new Transaction($collection, Transaction::UPDATE, $object);
        }
    }

    public function preRemove(LifecycleEventArgs $args)
    {
        $object = $args->getObject();
        if (!$this->collectionsOf($object)) {
            return;
        }

        $this->objectIds[spl_object_hash($object)] = method_exists($object, 'getId')
            ? (string) $object->getId()
            : (string) implode('-', $args->getObjectManager()->getClassMetadata($object::class)->getIdentifierValues($object));
    }

    public function postRemove(LifecycleEventArgs $args)
    {
        $object = $args->getObject();
        $objectHash = spl_object_hash($object);
        if (!isset($this->objectIds[$objectHash])) {
            return;
        }

        foreach ($this->collectionsOf($object) as $collection) {
            $this->transactions[] = new Transaction($collection, Transaction::REMOVE, $this->objectIds[$objectHash]);
        }
    }

    protected $first = true;

    public function postFlush(PostFlushEventArgs $args)
    {
        foreach ($this->transactions as $transaction) {
            try {
                $transaction->commit();
            } catch (TypesenseException|NetworkException $e) {
                if ($this->first) {
                    if ($this->onError) {
                        ($this->onError)($e);
                    }
                    $this->first = false;
                }
            }
        }

        if ($this->transactions) {
            $this->cache->clear();
        }
    }
}
