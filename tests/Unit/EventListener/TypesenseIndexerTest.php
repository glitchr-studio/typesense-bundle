<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Typesense\Bundle\EventListener\TypesenseIndexer;
use Typesense\Bundle\Exception\TypesenseException;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\Mapping\TypesenseDocuments;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadataField;
use Typesense\Bundle\ORM\Transformer\Abstract\AbstractTransformer;
use Typesense\Bundle\ORM\TypesenseManager;
use Typesense\Bundle\Tests\Fixtures\Attribute\Book;
use Typesense\Bundle\Tests\Fixtures\Attribute\Tag;

class TypesenseIndexerTest extends TestCase
{
    /** @var list<array> */
    private array $upserted = [];

    private function manager(bool $fails = false): TypesenseManager
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
        $documents->method('upsert')->willReturnCallback(function (array $data) use ($fails) {
            if ($fails) {
                throw new TypesenseException('Not Ready or Lagging', 503);
            }
            $this->upserted[] = $data;

            return $data;
        });

        $collection = $this->createStub(TypesenseCollection::class);
        $collection->method('name')->willReturn('book');
        $collection->method('metadata')->willReturn($metadata);
        $collection->method('transformer')->willReturn($transformer);
        $collection->method('documents')->willReturn($documents);
        $collection->method('supports')->willReturnCallback(fn ($o) => $o instanceof Book);

        $manager = new TypesenseManager('default');
        $manager->addCollection($collection);

        return $manager;
    }

    private function flush(TypesenseIndexer $indexer, object ...$objects): void
    {
        $om = $this->createStub(ObjectManager::class);
        foreach ($objects as $object) {
            $indexer->postPersist(new LifecycleEventArgs($object, $om));
        }
        $indexer->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));
    }

    public function testAnObjectOfAMappedClassIsIndexedWithoutImplementingAnything(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('clear');

        $this->flush(new TypesenseIndexer($this->manager(), null, $cache), new Book('Dune', 7), new Tag('not', 'mapped'));

        $this->assertSame([['id' => '7', 'title' => 'Dune']], $this->upserted);
    }

    public function testAFailureIsHandedToOnErrorOncePerFlush(): void
    {
        $errors = [];
        $this->flush(new TypesenseIndexer($this->manager(true), function (\Throwable $e) use (&$errors) { $errors[] = $e->getCode(); }), new Book('Dune', 7), new Book('Emma', 8));

        $this->assertSame([503], $errors);
    }

    /** The constructor of before 2.0, as an application may still define the service. */
    public function testTheRequestStackAndParameterBagOfBeforeStillWork(): void
    {
        $request = new Request();
        $request->setSession($session = new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        $this->flush(new TypesenseIndexer($this->manager(true), $stack, new ParameterBag(['kernel.cache_dir' => sys_get_temp_dir()])), new Book('Dune', 7));

        $this->assertSame(['Typesense 503: Not Ready or Lagging'], $session->getFlashBag()->get('warning'));
    }
}
