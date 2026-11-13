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
 * The indexing rule (persisted(), updated(), removing(), removed(), flush()),
 * and its adapter on Doctrine's events: an object of a mapped class is
 * upserted into each collection that supports it once it is persisted or
 * updated, and deleted from them once it is removed, all of it when the
 * unit of work is flushed; then the finders' cached responses are cleared.
 * Another persistence calls the same rule: omnisense/omnibridge's Lifecycle
 * events through Bridge\Omnibridge\LifecycleIndexer (omnibridge/eloquent).
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

    /**
     * The rule, whichever persistence calls it (Doctrine's events below; omnisense/omnibridge's Lifecycle events
     * through Bridge\Omnibridge\LifecycleIndexer): an object of a mapped class, once saved the first time.
     */
    public function persisted(object $object): void
    {
        foreach ($this->collectionsOf($object) as $collection) {
            $this->transactions[] = new Transaction($collection, Transaction::PERSIST, $object);
        }
    }

    /** An object of a mapped class, once a change of it is saved. */
    public function updated(object $object): void
    {
        foreach ($this->collectionsOf($object) as $collection) {
            $this->transactions[] = new Transaction($collection, Transaction::UPDATE, $object);
        }
    }

    /**
     * An object of a mapped class about to be deleted: its identifier taken while it still has one - getId(), else
     * what $identifier answers (the persistence's).
     *
     * @param ?\Closure(object): mixed $identifier
     */
    public function removing(object $object, ?\Closure $identifier = null): void
    {
        if (!$this->collectionsOf($object)) {
            return;
        }

        $id = method_exists($object, 'getId') ? $object->getId() : (null !== $identifier ? $identifier($object) : null);
        if (\is_array($id)) {
            /** @var array<scalar|\Stringable|null> $parts a composite identifier */
            $parts = $id;
            $id = implode('-', $parts);
        }
        $this->objectIds[spl_object_hash($object)] = (string) $id;
    }

    /** The object removing() saw, once deleted: its documents removed. */
    public function removed(object $object): void
    {
        $objectHash = spl_object_hash($object);
        if (!isset($this->objectIds[$objectHash])) {
            return;
        }

        foreach ($this->collectionsOf($object) as $collection) {
            $this->transactions[] = new Transaction($collection, Transaction::REMOVE, $this->objectIds[$objectHash]);
        }
        unset($this->objectIds[$objectHash]);
    }

    /**
     * Once the persistence has committed: what was queued sent, then the finders' cached responses cleared. A failure
     * to reach the server goes to $onError once per flush; the queue is emptied either way, so the next flush sends
     * only its own writes.
     */
    public function flush(): void
    {
        $transactions = $this->transactions;
        $this->transactions = [];
        $first = true;
        foreach ($transactions as $transaction) {
            try {
                $transaction->commit();
            } catch (TypesenseException|NetworkException $e) {
                if ($first) {
                    if ($this->onError) {
                        ($this->onError)($e);
                    }
                    $first = false;
                }
            }
        }

        if ($transactions) {
            $this->cache->clear();
        }
    }

    /** What was queued forgotten (a rollback). */
    public function clear(): void
    {
        $this->transactions = [];
        $this->objectIds = [];
    }

    public function postPersist(LifecycleEventArgs $args)
    {
        $this->persisted($args->getObject());
    }

    public function postUpdate(LifecycleEventArgs $args)
    {
        $this->updated($args->getObject());
    }

    public function preRemove(LifecycleEventArgs $args)
    {
        $manager = $args->getObjectManager();
        $this->removing($args->getObject(), static fn (object $object): array => $manager->getClassMetadata($object::class)->getIdentifierValues($object));
    }

    public function postRemove(LifecycleEventArgs $args)
    {
        $this->removed($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args)
    {
        $this->flush();
    }
}
