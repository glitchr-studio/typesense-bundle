<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\ORM\Mapping;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Transformer\Abstract\AbstractTransformer;
use Typesense\Bundle\Tests\Fixtures\FixtureArticle;

/**
 * `collection_prefix`: two environments (dev/test/demo...) pointed at the
 * same Typesense server used to write into the very same collection, since
 * a collection was named by its mapping key and nothing else. The prefix
 * only ever changes the name sent to the server — the mapping key is what
 * TypesenseManager, the finders and the service ids are keyed by, and an
 * application must not have to know which environment it runs in to ask
 * for "article".
 */
class TypesenseMetadataTest extends TestCase
{
    private function makeTransformer(?ClassMetadata $classMetadata = null): AbstractTransformer
    {
        $objectManager = $this->createStub(ObjectManager::class);
        if ($classMetadata) {
            $objectManager->method('getClassMetadata')->willReturn($classMetadata);
        }

        $transformer = $this->createStub(AbstractTransformer::class);
        $transformer->method('getObjectManager')->willReturn($objectManager);

        return $transformer;
    }

    private function makeConfiguration(?string $class = null): array
    {
        return ['class' => $class, 'fields' => ['title' => ['type' => 'string']]];
    }

    /**
     * Backward compatibility: without a prefix (the default, and the only
     * possibility before the option existed) the server-side name is the
     * mapping key, byte for byte.
     */
    public function testWithoutAPrefixTheCollectionIsNamedAfterItsMapping(): void
    {
        $metadata = new TypesenseMetadata('article', $this->makeConfiguration(), $this->makeTransformer());

        $this->assertSame('', $metadata->getPrefix());
        $this->assertSame('article', $metadata->getName());
        $this->assertSame('article', $metadata->getCollectionName());
        $this->assertSame('article', $metadata->getConfiguration()['name']);
    }

    public function testThePrefixOnlyChangesTheNameOnTheServer(): void
    {
        $metadata = new TypesenseMetadata('article', $this->makeConfiguration(), $this->makeTransformer(), 'test_');

        $this->assertSame('test_', $metadata->getPrefix());
        $this->assertSame('test_article', $metadata->getCollectionName());
        // What create() sends as the collection schema.
        $this->assertSame('test_article', $metadata->getConfiguration()['name']);

        // The mapping key, untouched.
        $this->assertSame('article', $metadata->getName());
        $this->assertSame('article', $metadata->name);
        $this->assertSame('article', $metadata->getRootName());
    }

    /**
     * A single-table-inheritance mapping spawns one sub-collection per
     * discriminator value ("article__blog"): each is a collection of its
     * own on the server, so each must carry the prefix too — otherwise
     * the root collection would be isolated and its sub-collections
     * still shared.
     */
    public function testDiscriminatorSubCollectionsInheritThePrefix(): void
    {
        $classMetadata = new ClassMetadata(FixtureArticle::class);
        $classMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $classMetadata->setDiscriminatorColumn(['name' => 'kind', 'type' => 'string']);
        $classMetadata->discriminatorMap = ['article' => FixtureArticle::class, 'blog' => FixtureBlogArticle::class];
        $classMetadata->subClasses = [FixtureBlogArticle::class];

        $metadata = new TypesenseMetadata('article', $this->makeConfiguration(FixtureArticle::class), $this->makeTransformer($classMetadata), 'test_');

        $subMetadata = $metadata->getSubMetadata();
        $this->assertCount(1, $subMetadata);

        $this->assertSame('article__blog', $subMetadata[0]->getName());
        $this->assertSame('article', $subMetadata[0]->getRootName());
        $this->assertSame('test_article__blog', $subMetadata[0]->getCollectionName());
        $this->assertSame('test_article__blog', $subMetadata[0]->getConfiguration()['name']);
    }
}

class FixtureBlogArticle extends FixtureArticle
{
}
