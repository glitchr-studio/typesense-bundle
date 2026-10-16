<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;
use Typesense\Bundle\Command\CreateCommand;
use Typesense\Bundle\DBAL\Connection;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\TypesenseFinder;
use Typesense\Bundle\ORM\TypesenseManager;
use Typesense\Collection;
use Typesense\Collections;

/**
 * `typesense:create` used to start by deleting EVERY collection it found
 * on each connection, mapped by the application or not — on a server
 * shared with another application, another environment or a collection
 * created outside the bundle, running it once emptied them all (a
 * development index was lost that way). It now only drops and recreates
 * the collections of its own mappings, and the sweep is gone for good
 * (no --all, no option at all).
 */
class CreateCommandTest extends TestCase
{
    /** Names of the collections deleted straight on the connection, bypassing the mappings: must stay empty. */
    private array $swept = [];

    /**
     * A server holding "foreign" (not ours) next to "test_article" (the
     * "article" mapping behind a "test_" prefix).
     *
     * @return array{TypesenseManager, TypesenseCollection&\PHPUnit\Framework\MockObject\MockObject, Connection&\PHPUnit\Framework\MockObject\MockObject, CacheInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeManager(): array
    {
        $this->swept = [];

        $collections = $this->createStub(Collections::class);
        $collections->method('retrieve')->willReturn([['name' => 'foreign'], ['name' => 'test_article']]);

        $connection = $this->createMock(Connection::class);
        $connection->method('getHealth')->willReturn(true);
        $connection->method('getCollections')->willReturn($collections);
        $connection->method('getCollection')->willReturnCallback(function (string $name) {
            $collection = $this->createStub(Collection::class);
            $collection->method('delete')->willReturnCallback(function () use ($name) {
                $this->swept[] = $name;

                return [];
            });

            return $collection;
        });

        $metadata = $this->createStub(TypesenseMetadata::class);
        $metadata->method('getName')->willReturn('article');
        $metadata->method('getCollectionName')->willReturn('test_article');

        $collection = $this->createMock(TypesenseCollection::class);
        $collection->method('metadata')->willReturn($metadata);

        $cache = $this->createMock(CacheInterface::class);
        $finder = $this->createStub(TypesenseFinder::class);
        $finder->method('cache')->willReturn($cache);

        $manager = $this->createStub(TypesenseManager::class);
        $manager->method('getConnections')->willReturn(['default' => $connection]);
        $manager->method('getCollections')->willReturn(['article' => $collection]);
        $manager->method('getFinder')->willReturn($finder);

        return [$manager, $collection, $connection, $cache];
    }

    public function testByDefaultOnlyTheMappedCollectionsAreDroppedAndRecreated(): void
    {
        [$manager, $collection, $connection, $cache] = $this->makeManager();

        // The server is never asked what else it holds, let alone to drop it.
        $connection->expects($this->never())->method('getCollections');
        $connection->expects($this->never())->method('getCollection');

        $collection->expects($this->once())->method('delete');
        $collection->expects($this->once())->method('create');
        $cache->expects($this->once())->method('clear');

        $tester = new CommandTester(new CreateCommand($manager));
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame([], $this->swept);

        // Both names are shown: the mapping key and what it is on the server.
        $this->assertStringContainsString('Deleting article (test_article in Typesense)', $tester->getDisplay());
        $this->assertStringContainsString('Creating article', $tester->getDisplay());
        $this->assertStringNotContainsString('foreign', $tester->getDisplay());
    }

    /**
     * The whole-server sweep is gone, not merely hidden: there is no
     * option left that would bring it back.
     */
    public function testThereIsNoOptionToSweepTheServer(): void
    {
        [$manager, $collection, $connection] = $this->makeManager();

        $command = new CreateCommand($manager);
        $this->assertSame([], $command->getDefinition()->getOptions());

        $connection->expects($this->never())->method('getCollections');
        $connection->expects($this->never())->method('getCollection');
        $collection->expects($this->never())->method('delete');

        $this->expectException(InvalidOptionException::class);
        (new CommandTester($command))->execute(['--all' => true]);
    }
}
