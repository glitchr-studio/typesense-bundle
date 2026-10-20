<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\ORM\Transformer;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Typesense\Bundle\ORM\Transformer\PropertyReader;
use Typesense\Bundle\Tests\Fixtures\Attribute\Book;
use Typesense\Bundle\Tests\Fixtures\Attribute\Tag;
use Typesense\Bundle\Tests\Fixtures\FixtureArticle;

/**
 * What TypesenseTrait::__typesenseGetter() did inside an entity, done from
 * outside on an object that implements nothing.
 */
class PropertyReaderTest extends TestCase
{
    public function testAPathThroughACollectionReadsEveryItem(): void
    {
        $book = new Book('Dune', 3, new Tag('Science fiction', 'sf'), new Tag('Desert', 'desert'));

        $this->assertSame('Dune', PropertyReader::read($book, 'title', ['type' => 'string']));
        $this->assertSame(['sf', 'desert'], PropertyReader::read($book, 'tags.slug', ['type' => 'string[]']));
        $this->assertSame(['Science fiction', 'Desert'], PropertyReader::read($book, 'tags', ['type' => 'string[]']), 'objects by their __toString()');
        $this->assertTrue(PropertyReader::read($book, 'isAvailable', ['type' => 'bool']));
    }

    public function testASingleValueOfAnArrayFieldIsWrapped(): void
    {
        $this->assertSame(['Dune'], PropertyReader::read(new Book('Dune', 1), 'title', ['type' => 'string[]']));
    }

    public function testItReadsAsTheTraitDoes(): void
    {
        $article = new FixtureArticle();
        $article->id = 1;
        $article->title = 'Hello';
        $article->tags = new ArrayCollection([new FixtureArticle(), new FixtureArticle()]);
        $article->tags[0]->title = 'one';
        $article->tags[1]->title = 'two';

        foreach ([['title', 'string'], ['tags', 'string[]'], ['title', 'string[]']] as [$path, $type]) {
            $this->assertSame($article->__typesenseGetter($path, ['type' => $type]), PropertyReader::read($article, $path, ['type' => $type]));
        }
        $this->assertSame(['one', 'two'], PropertyReader::read($article, 'tags', ['type' => 'string[]']), 'a TypesenseInterface by its __typesense()');
    }
}
