<?php

declare(strict_types=1);

namespace Typesense\Bundle\Bridge\Omnibridge;

use Omnibridge\Persistence\Lifecycle\PostFlush;
use Omnibridge\Persistence\Lifecycle\PostPersist;
use Omnibridge\Persistence\Lifecycle\PostRemove;
use Omnibridge\Persistence\Lifecycle\PostUpdate;
use Omnibridge\Persistence\Lifecycle\PreRemove;
use Typesense\Bundle\EventListener\TypesenseIndexer;

/**
 * The indexing rule on omnisense/omnibridge's Lifecycle events: what a persistence other than Doctrine writes
 * (omnibridge/eloquent, which dispatches these events) reaches the index as Doctrine's writes do through
 * TypesenseIndexer's own Doctrine listener. Each event to the rule, the identifier of a removed object from the event's
 * unit of work; the others (PrePersist, PreUpdate, PreFlush, OnFlush, PostLoad) ignored.
 *
 * A host hands it the events when it carries the tag omnibridge.lifecycle_listener (omnisense/omnibridge's
 * Lifecycle\Listeners::TAG): the Symfony bundle tags it where omnisense/omnibridge is installed; by hand,
 * `EloquentPersistence::create($connection, ['listeners' => [new LifecycleIndexer($indexer)]])`.
 */
final class LifecycleIndexer
{
    public const TAG = 'omnibridge.lifecycle_listener';

    public function __construct(private readonly TypesenseIndexer $indexer)
    {
    }

    public function __invoke(object $event): void
    {
        match (true) {
            $event instanceof PostPersist => $this->indexer->persisted($event->entity),
            $event instanceof PostUpdate => $this->indexer->updated($event->entity),
            $event instanceof PreRemove => $this->indexer->removing($event->entity, static fn (object $entity): mixed => $event->unitOfWork->identifier($entity)),
            $event instanceof PostRemove => $this->indexer->removed($event->entity),
            $event instanceof PostFlush => $this->indexer->flush(),
            default => null,
        };
    }
}
