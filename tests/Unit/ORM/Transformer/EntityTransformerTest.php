<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\ORM\Transformer;

use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Typesense\Bundle\ORM\Mapping\AttributeReader;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Transformer\EntityTransformer;
use Typesense\Bundle\Tests\Fixtures\Attribute\Book;
use Typesense\Bundle\Tests\Fixtures\Attribute\Tag;
use Typesense\Bundle\Tests\Fixtures\EntityManagers;

class EntityTransformerTest extends TestCase
{
    public function testAnEntityThatImplementsNothingIsConvertedByItsMapping(): void
    {
        $transformer = new EntityTransformer(EntityManagers::sqlite());
        $transformer->addMapping(new TypesenseMetadata('book', AttributeReader::read(Book::class)[1], $transformer));

        $book = new Book('Dune', 3, new Tag('Science fiction', 'sf'));
        $this->assertSame(Book::class, $transformer->mappedClass($book));
        $this->assertSame([
            'title' => 'Dune',
            'tags' => ['Science fiction'],
            'tagSlugs' => ['sf'],
            'summary' => '',
            'rank' => 3,
            'available' => true,
            'id' => '',
            'primary' => null,
        ], $transformer->convert($book), 'the identifier is added by the metadata (null before the flush)');
    }

    public function testAnObjectOfNoMappedClassIsRefused(): void
    {
        $transformer = new EntityTransformer($this->createStub(ObjectManager::class));

        $this->expectExceptionMessage('is not supported');
        $transformer->convert(new Tag('a', 'a'));
    }
}
