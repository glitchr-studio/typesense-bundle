<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Integration;

use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Typesense\Bundle\Command\CreateCommand;
use Typesense\Bundle\DBAL\Connection;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Transformer\EntityTransformer;
use Typesense\Bundle\ORM\TypesenseFinder;
use Typesense\Bundle\ORM\TypesenseManager;

/**
 * Unlike DocumentRoundTripTest, this one goes through the bundle's own
 * Connection/TypesenseCollection/CreateCommand, against a real server: it
 * is the bundle's naming of the collections that is under test — two
 * "environments" (the same mapping, without and with a
 * `collection_prefix`) sharing one server, and `typesense:create` run by
 * one of them.
 *
 * `typesense:create --all` is deliberately NOT run here: it empties the
 * whole server, and TYPESENSE_URL may well point at one somebody cares
 * about. The Unit/ suite covers it with mocks.
 */
class CollectionPrefixTest extends TestCase
{
    private Connection $connection;
    private string $name;

    protected function setUp(): void
    {
        $url = getenv('TYPESENSE_URL') ?: 'http://localhost:8108';
        $parts = parse_url($url);

        $this->connection = new Connection('default', new ParameterBag([
            'typesense.connections.default.secret' => getenv('TYPESENSE_KEY') ?: 'xyz',
            'typesense.connections.default.scheme' => $parts['scheme'] ?? 'http',
            'typesense.connections.default.host' => $parts['host'],
            'typesense.connections.default.port' => $parts['port'] ?? 8108,
            'typesense.connections.default.options.connection_timeout_seconds' => 2,
        ]));

        try {
            $this->connection->getHealth();
        } catch (\Exception $e) {
            // \Exception, not \Throwable: an \Error here is a broken bundle
            // (a missing function, a TypeError), not an unreachable server.
            $this->markTestSkipped('No reachable Typesense server at ' . $url . ': ' . $e->getMessage());
        }

        $this->name = 'typesense_bundle_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (['', 'test_'] as $prefix) {
            try {
                $this->connection->getCollection($prefix . $this->name)->delete();
            } catch (\Throwable $e) {
                // Already gone / never created — fine.
            }
        }
    }

    private function makeCollection(string $prefix): TypesenseCollection
    {
        $metadata = new TypesenseMetadata($this->name, [
            'fields' => [
                'title' => ['type' => 'string'],
                'rank' => ['type' => 'int32'],
            ],
            'default_sorting_field' => 'rank',
        ], new EntityTransformer($this->createStub(ObjectManager::class)), $prefix);

        return new TypesenseCollection($metadata, $this->connection);
    }

    /**
     * What `bin/console typesense:create` does in an application whose
     * only mapping is $collection.
     */
    private function runCreateCommand(TypesenseCollection $collection): void
    {
        $finder = $this->createStub(TypesenseFinder::class);
        $finder->method('name')->willReturn($collection->name());
        $finder->method('cache')->willReturn($this->createStub(CacheInterface::class));

        $manager = new TypesenseManager('default');
        $manager->addConnection($this->connection);
        $manager->addCollection($collection);
        $manager->addFinder($finder);

        $this->assertSame(0, (new CommandTester(new CreateCommand($manager)))->execute([]));
    }

    private function serverCollections(): array
    {
        return array_column($this->connection->getCollections()->retrieve(), 'name');
    }

    private function titles(TypesenseCollection $collection): array
    {
        $results = $collection->documents()->search(['q' => '*', 'query_by' => 'title']);

        return array_map(fn(array $hit) => $hit['document']['title'], $results['hits']);
    }

    public function testTwoEnvironmentsShareAServerWithoutSharingACollection(): void
    {
        $dev = $this->makeCollection('');
        $test = $this->makeCollection('test_');

        // The mapping key is the same on both sides: only the server tells them apart.
        $this->assertSame($dev->name(), $test->name());

        $dev->create();
        $dev->documents()->create(['id' => '1', 'title' => 'Written by dev', 'rank' => 1], []);

        // The test environment (re)builds its index...
        $this->runCreateCommand($test);
        $test->documents()->upsert(['id' => '1', 'title' => 'Written by test', 'rank' => 1], []);

        // ...next to dev's, which typesense:create did not touch.
        $this->assertContains($this->name, $this->serverCollections());
        $this->assertContains('test_' . $this->name, $this->serverCollections());
        $this->assertSame(['Written by dev'], $this->titles($dev));
        $this->assertSame(['Written by test'], $this->titles($test));

        // Run again, typesense:create empties its own collection (dropped
        // and recreated), and still nobody else's.
        $this->runCreateCommand($test);

        $this->assertSame([], $this->titles($test));
        $this->assertSame(['Written by dev'], $this->titles($dev));

        // Same for a plain delete(): the prefixed collection goes, alone.
        $test->delete();

        $this->assertNotContains('test_' . $this->name, $this->serverCollections());
        $this->assertSame(['Written by dev'], $this->titles($dev));
    }
}
