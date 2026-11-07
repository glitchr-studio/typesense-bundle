<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\Bridge;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use Omnibridge\Persistence\Lifecycle\OnFlush;
use Omnibridge\Persistence\Lifecycle\PostFlush;
use Omnibridge\Persistence\Lifecycle\PostLoad;
use Omnibridge\Persistence\Lifecycle\PostPersist;
use Omnibridge\Persistence\Lifecycle\PostRemove;
use Omnibridge\Persistence\Lifecycle\PostUpdate;
use Omnibridge\Persistence\Lifecycle\PrePersist;
use Omnibridge\Persistence\Lifecycle\PreRemove;
use Omnibridge\Persistence\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Typesense\Bundle\Bridge\Omnibridge\LifecycleIndexer;
use Typesense\Bundle\EventListener\TypesenseIndexer;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\Mapping\TypesenseDocuments;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadataField;
use Typesense\Bundle\ORM\Transformer\Abstract\AbstractTransformer;
use Typesense\Bundle\ORM\TypesenseManager;
use Typesense\Bundle\Tests\Fixtures\Attribute\Book;
use Typesense\Bundle\Tests\Fixtures\Attribute\Tag;

/**
 * The indexing rule on omnisense/omnibridge's Lifecycle events (omnibridge/eloquent's writes) sends what the Doctrine
 * listener sends for the same writes: the upserts of what is persisted and updated, the removal of what is removed by
 * the identifier taken before, once per flush - and each flush only its own.
 */
final class LifecycleIndexerTest extends TestCase
{
    /** @var list<array{string, mixed}> */
    private array $sent = [];

    protected function setUp(): void
    {
        if (!class_exists(PostFlush::class)) {
            self::markTestSkipped('omnisense/omnibridge is not installed.');
        }
    }

    private function indexer(): TypesenseIndexer
    {
        $id = new TypesenseMetadataField();
        $id->name = 'id';
        $id->type = 'string';
        $id->identifier = true;

        $metadata = $this->createStub(TypesenseMetadata::class);
        $metadata->fields = ['id' => $id];
        $metadata->method('getName')->willReturn('book');
        $metadata->method('getSubMetadata')->willReturn([]);

        $transformer = $this->createStub(AbstractTransformer::class);
        $transformer->method('convert')->willReturnCallback(fn (Book $b) => ['id' => (string) $b->getRank(), 'title' => $b->getTitle()]);
        $metadata->method('getTransformer')->willReturn($transformer);

        $documents = $this->createStub(TypesenseDocuments::class);
        $documents->method('upsert')->willReturnCallback(function (array $data) {
            $this->sent[] = ['upsert', $data];

            return $data;
        });
        $documents->method('delete')->willReturnCallback(function (string|int $id) {
            $this->sent[] = ['delete', (string) $id];

            return [];
        });

        $collection = $this->createStub(TypesenseCollection::class);
        $collection->method('name')->willReturn('book');
        $collection->method('metadata')->willReturn($metadata);
        $collection->method('transformer')->willReturn($transformer);
        $collection->method('documents')->willReturn($documents);
        $collection->method('supports')->willReturnCallback(fn ($o) => $o instanceof Book);

        $manager = new TypesenseManager('default');
        $manager->addCollection($collection);

        return new TypesenseIndexer($manager);
    }

    /** @return list<array{string, mixed}> what the Doctrine listener sends for: two books persisted, one updated, one removed, in two flushes */
    private function doctrine(Book $dune, Book $emma, Tag $tag): array
    {
        $this->sent = [];
        $indexer = $this->indexer();
        $om = $this->createStub(ObjectManager::class);
        $indexer->postPersist(new LifecycleEventArgs($dune, $om));
        $indexer->postPersist(new LifecycleEventArgs($emma, $om));
        $indexer->postPersist(new LifecycleEventArgs($tag, $om));
        $indexer->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));
        $dune->setTitle('Dune Messiah');
        $indexer->postUpdate(new LifecycleEventArgs($dune, $om));
        $indexer->preRemove(new LifecycleEventArgs($emma, $om));
        $indexer->postRemove(new LifecycleEventArgs($emma, $om));
        $indexer->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));

        return $this->sent;
    }

    /** @return list<array{string, mixed}> what the Lifecycle adapter sends for the same writes, as omnibridge/eloquent dispatches them */
    private function lifecycle(Book $dune, Book $emma, Tag $tag): array
    {
        $this->sent = [];
        $listener = new LifecycleIndexer($this->indexer());
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('identifier')->willReturnCallback(static fn (object $entity) => $entity->getId());
        foreach ([$dune, $emma, $tag] as $entity) {
            $listener(new PrePersist($entity, $unitOfWork));
        }
        $listener(new OnFlush($unitOfWork));
        foreach ([$dune, $emma, $tag] as $entity) {
            $listener(new PostPersist($entity, $unitOfWork));
        }
        $listener(new PostFlush($unitOfWork));
        $listener(new PostLoad($dune, $unitOfWork));
        $dune->setTitle('Dune Messiah');
        $listener(new PreRemove($emma, $unitOfWork));
        $listener(new OnFlush($unitOfWork));
        $listener(new PostUpdate($dune, $unitOfWork));
        $listener(new PostRemove($emma, $unitOfWork));
        $listener(new PostFlush($unitOfWork));

        return $this->sent;
    }

    /** A book as a persistence leaves it after its insert: with its identifier. */
    private static function stored(string $title, int $rank, int $id): Book
    {
        $book = new Book($title, $rank);
        (new \ReflectionProperty(Book::class, 'id'))->setValue($book, $id);

        return $book;
    }

    public function testTheSameWritesSendTheSameDocumentsAsDoctrines(): void
    {
        $doctrine = $this->doctrine(self::stored('Dune', 7, 1), self::stored('Emma', 8, 2), new Tag('not', 'mapped'));
        $lifecycle = $this->lifecycle(self::stored('Dune', 7, 1), self::stored('Emma', 8, 2), new Tag('not', 'mapped'));

        self::assertSame([
            ['upsert', ['id' => '7', 'title' => 'Dune']],
            ['upsert', ['id' => '8', 'title' => 'Emma']],
            ['upsert', ['id' => '7', 'title' => 'Dune Messiah']],
            ['delete', '2'],
        ], $doctrine, 'each flush sends its own writes');
        self::assertSame($doctrine, $lifecycle);
    }

    public function testTheTagIsOmnibridgesLifecycleListeners(): void
    {
        self::assertSame(\Omnibridge\Persistence\Lifecycle\Listeners::TAG, LifecycleIndexer::TAG);
    }
}
