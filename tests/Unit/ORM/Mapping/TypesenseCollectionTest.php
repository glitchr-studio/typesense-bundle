<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\ORM\Mapping;

use PHPUnit\Framework\TestCase;
use Typesense\Bundle\DBAL\Connection;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadataField;
use Typesense\Bundle\ORM\Query\Query;
use Typesense\Bundle\ORM\Transformer\Abstract\AbstractTransformer;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\MultiSearch;

class TypesenseCollectionTest extends TestCase
{
    /**
     * @return array{TypesenseCollection, Client&\PHPUnit\Framework\MockObject\Stub}
     */
    private function makeCollection(string $prefix): array
    {
        $field = new TypesenseMetadataField();
        $field->name = 'title';
        $field->type = 'string';

        $transformer = $this->createStub(AbstractTransformer::class);
        $transformer->method('cast')->willReturnArgument(0);

        $metadata = $this->createStub(TypesenseMetadata::class);
        $metadata->method('getName')->willReturn('article');
        $metadata->method('getPrefix')->willReturn($prefix);
        $metadata->method('getCollectionName')->willReturn($prefix . 'article');
        $metadata->method('getTransformer')->willReturn($transformer);
        $metadata->method('getConfiguration')->willReturn(['name' => $prefix . 'article', 'fields' => ['title' => $field]]);

        $client = $this->createStub(Client::class);

        $connection = $this->createStub(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('getClient')->willReturn($client);

        return [new TypesenseCollection($metadata, $connection), $client];
    }

    /**
     * name() is the key TypesenseManager registers the collection under
     * (and the one getFinder() is asked with): the prefix never shows there.
     */
    public function testNameStaysTheMappingKey(): void
    {
        [$collection] = $this->makeCollection('test_');

        $this->assertSame('article', $collection->name());
    }

    public function testCreateSendsThePrefixedNameAsTheSchemaName(): void
    {
        [$collection, $client] = $this->makeCollection('test_');

        $collections = $this->createMock(Collections::class);
        $collections->expects($this->once())->method('create')->with($this->callback(
            fn(array $schema) => 'test_article' === $schema['name'] && 'title' === $schema['fields'][0]['name']
        ))->willReturn([]);
        $client->method('getCollections')->willReturn($collections);

        $collection->create();
    }

    /**
     * delete() used to look the collection up by name(): with a prefix
     * that is somebody else's collection — the unprefixed one, typically
     * the development index — and this environment's own would survive.
     */
    public function testDeleteDropsThePrefixedCollectionAndNoOther(): void
    {
        [$collection, $client] = $this->makeCollection('test_');

        $typesenseCollection = $this->createMock(Collection::class);
        $typesenseCollection->expects($this->once())->method('delete')->willReturn([]);

        $collections = $this->createMock(Collections::class);
        $collections->expects($this->once())->method('offsetGet')->with('test_article')->willReturn($typesenseCollection);
        $client->method('getCollections')->willReturn($collections);

        $collection->delete();
    }

    /**
     * A multiSearch names the collection of each search itself — by its
     * mapping key, the only name an application knows.
     */
    public function testMultiSearchPrefixesTheCollectionOfEachSearch(): void
    {
        [$collection, $client] = $this->makeCollection('test_');

        $multiSearch = $this->createMock(MultiSearch::class);
        $multiSearch->expects($this->once())->method('perform')->with($this->callback(
            fn(array $body) => ['test_article', 'test_author'] === array_column($body['searches'], 'collection')
        ), [])->willReturn(['results' => []]);
        $client->method('getMultiSearch')->willReturn($multiSearch);

        $collection->multiSearch([
            (new Query('title'))->addHeader('collection', 'article'),
            (new Query('name'))->addHeader('collection', 'author'),
        ], null);
    }

    public function testMultiSearchLeavesTheCollectionsAloneWithoutAPrefix(): void
    {
        [$collection, $client] = $this->makeCollection('');

        $query = (new Query('title'))->addHeader('collection', 'article');

        $multiSearch = $this->createMock(MultiSearch::class);
        $multiSearch->expects($this->once())->method('perform')->with(['searches' => [$query->getHeaders()]], [])->willReturn(['results' => []]);
        $client->method('getMultiSearch')->willReturn($multiSearch);

        $collection->multiSearch([$query], null);
    }
}
