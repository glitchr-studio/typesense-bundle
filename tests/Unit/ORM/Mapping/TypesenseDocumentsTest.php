<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\ORM\Mapping;

use PHPUnit\Framework\TestCase;
use Typesense\Bundle\DBAL\Connection;
use Typesense\Bundle\ORM\Mapping\TypesenseDocuments;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Document;
use Typesense\Documents;

/**
 * Every document operation picks its collection on the server by
 * TypesenseMetadata::getCollectionName() (the mapping key behind the
 * configured `collection_prefix`), never by the bare mapping key — one
 * call left on getName() and that operation alone would keep writing
 * into (or reading from) another environment's collection.
 */
class TypesenseDocumentsTest extends TestCase
{
    /**
     * @return array{TypesenseDocuments, Documents&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeDocuments(string $collectionName): array
    {
        $metadata = $this->createStub(TypesenseMetadata::class);
        $metadata->method('getName')->willReturn('article');
        $metadata->method('getCollectionName')->willReturn($collectionName);

        $documents = $this->createMock(Documents::class);

        $collection = $this->createStub(Collection::class);
        $collection->documents = $documents;

        // The only way to a collection: asked for by exactly this name, once.
        $collections = $this->createMock(Collections::class);
        $collections->expects($this->once())->method('offsetGet')->with($collectionName)->willReturn($collection);

        $connection = $this->createStub(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('getCollections')->willReturn($collections);

        return [new TypesenseDocuments($metadata, $connection), $documents];
    }

    public function testWritesTargetThePrefixedCollection(): void
    {
        foreach (['create', 'upsert', 'update'] as $operation) {
            [$typesenseDocuments, $documents] = $this->makeDocuments('test_article');
            $documents->expects($this->once())->method($operation)->with(['id' => '1'], [])->willReturn(['id' => '1']);

            $this->assertSame(['id' => '1'], $typesenseDocuments->$operation(['id' => '1'], []), $operation);
        }
    }

    public function testImportTargetsThePrefixedCollection(): void
    {
        [$typesenseDocuments, $documents] = $this->makeDocuments('test_article');
        $documents->expects($this->once())->method('import')->with([['id' => '1']], ['action' => 'upsert'])->willReturn([['success' => true]]);

        $this->assertSame([['success' => true]], $typesenseDocuments->import([['id' => '1']], 'upsert'));
    }

    public function testSearchTargetsThePrefixedCollection(): void
    {
        [$typesenseDocuments, $documents] = $this->makeDocuments('test_article');
        $documents->expects($this->once())->method('search')->with(['q' => 'mouse'])->willReturn(['found' => 0]);

        $this->assertSame(['found' => 0], $typesenseDocuments->search(['q' => 'mouse']));
    }

    public function testDeleteTargetsThePrefixedCollection(): void
    {
        [$typesenseDocuments, $documents] = $this->makeDocuments('test_article');

        $document = $this->createMock(Document::class);
        $document->expects($this->once())->method('delete')->willReturn(['id' => '1']);
        $documents->expects($this->once())->method('offsetGet')->with('1')->willReturn($document);

        $this->assertSame(['id' => '1'], $typesenseDocuments->delete('1'));
    }

    /**
     * Backward compatibility: without a prefix getCollectionName() is the
     * mapping key, so the collection asked for is the one it always was.
     */
    public function testWithoutAPrefixTheMappingKeyIsTheCollection(): void
    {
        [$typesenseDocuments, $documents] = $this->makeDocuments('article');
        $documents->expects($this->once())->method('upsert')->willReturn([]);

        $typesenseDocuments->upsert(['id' => '1'], []);
    }
}
