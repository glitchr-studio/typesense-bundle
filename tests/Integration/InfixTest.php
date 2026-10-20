<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Integration;

use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Typesense\Bundle\DBAL\Connection;
use Typesense\Bundle\ORM\Mapping\TypesenseCollection;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Query\Query;
use Typesense\Bundle\ORM\Transformer\EntityTransformer;

/**
 * Infix search end to end, against a real server: a mapping's `infix: true`
 * builds the field's infix index when the collection is created, and a
 * Query over several fields asking for infix=always finds a word by its
 * middle ("ouse" in "mouse").
 */
class InfixTest extends TestCase
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
            $this->markTestSkipped('No reachable Typesense server at ' . $url . ': ' . $e->getMessage());
        }

        $this->name = 'typesense_bundle_infix_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        try {
            $this->connection->getCollection($this->name)->delete();
        } catch (\Throwable $e) {
        }
    }

    public function testAnInfixFieldIsFoundByTheMiddleOfAWord(): void
    {
        $metadata = new TypesenseMetadata($this->name, ['fields' => [
            'title' => ['type' => 'string', 'infix' => true],
            'content' => ['type' => 'string', 'infix' => true],
            'rank' => ['type' => 'int32'],
        ], 'default_sorting_field' => 'rank'], new EntityTransformer($this->createStub(ObjectManager::class)));
        $collection = new TypesenseCollection($metadata, $this->connection);
        $collection->create();

        $schema = $this->connection->getCollection($this->name)->retrieve();
        $this->assertSame(['title' => true, 'content' => true, 'rank' => false], array_column(array_map(fn ($f) => ['name' => $f['name'], 'infix' => $f['infix'] ?? false], $schema['fields']), 'infix', 'name'));

        $collection->documents()->upsert(['id' => '1', 'title' => 'Wireless mouse', 'content' => 'A small device', 'rank' => 1], []);
        $collection->documents()->upsert(['id' => '2', 'title' => 'Keyboard', 'content' => 'Wired', 'rank' => 2], []);

        $query = (new Query('title, content', 'ouse'))->numTypos(0);
        $this->assertSame(0, $collection->search($query)['found'], 'without infix, the middle of a word finds nothing');

        $query->infix(Query::INFIX_ALWAYS);
        $result = $collection->search($query);
        $this->assertSame(1, $result['found']);
        $this->assertSame('1', $result['hits'][0]['document']['id']);
    }
}
