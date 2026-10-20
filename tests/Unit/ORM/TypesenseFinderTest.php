<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\ORM;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Query\Query;
use Typesense\Bundle\ORM\Query\Response;
use Typesense\Bundle\ORM\TypesenseFinder;
use Typesense\Bundle\Tests\Fixtures\FixtureArticle;

class TypesenseFinderTest extends TestCase
{
    /** @var list<array<string, mixed>> the search parameters the collection was asked with */
    private array $searches = [];

    private function makeFinder(array $result): TypesenseFinder
    {
        $classMetadata = new ClassMetadata(FixtureArticle::class);

        $objectManager = $this->createStub(ObjectManager::class);
        $objectManager->method('getClassMetadata')->willReturn($classMetadata);

        $metadata = $this->createStub(TypesenseMetadata::class);
        $metadata->method('getObjectManager')->willReturn($objectManager);
        $metadata->class = FixtureArticle::class;
        $metadata->name = 'article';

        $collection = $this->createStub(TypesenseCollection::class);
        $collection->method('metadata')->willReturn($metadata);
        $collection->method('name')->willReturn('article');
        $collection->method('search')->willReturnCallback(function (Query $query) use ($result) {
            $this->searches[] = $query->getHeaders();

            return $result;
        });

        return new TypesenseFinder($collection, new ParameterBag(['kernel.debug' => true, 'kernel.cache_dir' => sys_get_temp_dir()]), $this->createStub(CacheInterface::class));
    }

    /**
     * facet() used to set facet_by to Query::INFIX_ALWAYS - the string
     * "always" - instead of the fields it was given: Typesense answered
     * "Could not find a facet field named `always`" and facet() returned
     * no facet at all.
     */
    public function testFacetAsksForTheFieldsItIsGiven(): void
    {
        $finder = $this->makeFinder(['facet_counts' => [
            ['field_name' => 'tags', 'counts' => [['value' => 'b-tag', 'count' => 1], ['value' => 'a-tag', 'count' => 3]]],
        ]]);

        $query = (new Query('state'))->addFilterBy('state:=publish');
        $facets = $finder->facet('tags', $query, ['tags' => ['a-tag']], true, Response::FACETS_USE_KEY);

        $this->assertSame('tags', $this->searches[0][Query::FACET_BY]);
        $this->assertSame('state:=publish', $this->searches[0][Query::FILTER_BY]);
        $this->assertSame(['a-tag', 'b-tag'], array_keys($facets['tags']['counts']));
        $this->assertSame(['value' => 'a-tag', 'count' => 3, 'checked' => true], $facets['tags']['counts']['a-tag']);
        $this->assertFalse($query->hasHeader(Query::FACET_BY), 'the query given is left as it was');
    }

    public function testFacetWithoutAQuerySearchesTheFacetFieldsThemselves(): void
    {
        $finder = $this->makeFinder(['facet_counts' => []]);

        $finder->facet('tags,state');

        $this->assertSame('tags,state', $this->searches[0][Query::QUERY_BY]);
        $this->assertSame('tags,state', $this->searches[0][Query::FACET_BY]);
    }
}
