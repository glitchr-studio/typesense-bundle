<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Typesense\Bundle\Command\ListCommand;
use Typesense\Bundle\DBAL\Connection;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\TypesenseManager;
use Typesense\Collections;

/**
 * typesense:list shows the server as it is — every collection, under its
 * real name. Its --collection filter takes that real name, and also a
 * mapping key, which is looked up behind the configured
 * `collection_prefix` ("article" finds "test_article").
 */
class ListCommandTest extends TestCase
{
    private function makeManager(): TypesenseManager
    {
        $collections = $this->createStub(Collections::class);
        $collections->method('retrieve')->willReturn([['name' => 'foreign'], ['name' => 'test_article']]);

        $connection = $this->createStub(Connection::class);
        $connection->method('getHealth')->willReturn(true);
        $connection->method('getCollections')->willReturn($collections);

        $metadata = $this->createStub(TypesenseMetadata::class);
        $metadata->method('getCollectionName')->willReturn('test_article');

        $collection = $this->createStub(TypesenseCollection::class);
        $collection->method('metadata')->willReturn($metadata);

        $manager = $this->createStub(TypesenseManager::class);
        $manager->method('getConnections')->willReturn(['default' => $connection]);
        $manager->method('getCollections')->willReturn(['article' => $collection]);
        $manager->method('getCollection')->willReturn($collection);

        return $manager;
    }

    public function testListsEveryCollectionOfTheServerUnderItsRealName(): void
    {
        $tester = new CommandTester(new ListCommand($this->makeManager()));
        $tester->execute([]);

        $this->assertStringContainsString('[foreign]', $tester->getDisplay());
        $this->assertStringContainsString('[test_article]', $tester->getDisplay());
    }

    public function testCollectionFilterAcceptsAMappingKey(): void
    {
        $tester = new CommandTester(new ListCommand($this->makeManager()));
        $tester->execute(['--collection' => 'article']);

        $this->assertStringContainsString('[test_article]', $tester->getDisplay());
        $this->assertStringNotContainsString('[foreign]', $tester->getDisplay());
    }

    public function testCollectionFilterStillAcceptsTheNameOnTheServer(): void
    {
        $tester = new CommandTester(new ListCommand($this->makeManager()));
        $tester->execute(['--collection' => 'foreign']);

        $this->assertStringContainsString('[foreign]', $tester->getDisplay());
        $this->assertStringNotContainsString('[test_article]', $tester->getDisplay());
    }
}
