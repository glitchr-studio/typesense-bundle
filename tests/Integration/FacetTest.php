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
 * Facets end to end, against a real server: a Query searching one field
 * and counting two gets both counts back (facet_by is not cut to the
 * number of query_by fields).
 */
class FacetTest extends TestCase
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

        $this->name = 'typesense_bundle_facet_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        try {
            $this->connection->getCollection($this->name)->delete();
        } catch (\Throwable $e) {
        }
    }

    public function testEveryFacetAskedIsCountedOverASingleQueryByField(): void
    {
        $metadata = new TypesenseMetadata($this->name, ['fields' => [
            'state' => ['type' => 'string', 'facet' => true],
            'tags' => ['type' => 'string[]', 'facet' => true],
            'rank' => ['type' => 'int32'],
        ], 'default_sorting_field' => 'rank'], new EntityTransformer($this->createStub(ObjectManager::class)));
        $collection = new TypesenseCollection($metadata, $this->connection);
        $collection->create();

        $collection->documents()->upsert(['id' => '1', 'state' => 'published', 'tags' => ['travel', 'food'], 'rank' => 1], []);
        $collection->documents()->upsert(['id' => '2', 'state' => 'published', 'tags' => ['travel'], 'rank' => 2], []);
        $collection->documents()->upsert(['id' => '3', 'state' => 'draft', 'tags' => ['music'], 'rank' => 3], []);

        $query = (new Query('state', '*'))->facetBy('tags,state')->perPage(0);
        $result = $collection->search($query);

        $counts = [];
        foreach ($result['facet_counts'] as $facet) {
            $counts[$facet['field_name']] = array_column($facet['counts'], 'count', 'value');
            ksort($counts[$facet['field_name']]);
        }
        ksort($counts);
        $this->assertSame([
            'state' => ['draft' => 1, 'published' => 2],
            'tags' => ['food' => 1, 'music' => 1, 'travel' => 2],
        ], $counts);
    }
}
