<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\Attribute;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;
use Typesense\Bundle\Bridge\Symfony\DependencyInjection\Configuration;
use Typesense\Bundle\ORM\Mapping\AttributeReader;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Transformer\EntityTransformer;
use Typesense\Bundle\Tests\Fixtures\EntityManagers;
use Typesense\Bundle\Tests\Fixtures\Attribute\Book;
use Typesense\Bundle\Tests\Fixtures\Attribute\Tag;

/**
 * A collection declared by attributes is the very mapping the
 * configuration would hold: the same array once the configuration tree has
 * filled its defaults, so the same metadata and the same schema.
 */
class AttributeReaderTest extends TestCase
{
    private const YAML = <<<'YAML'
        typesense:
          mappings:
            book:
              class: 'Typesense\Bundle\Tests\Fixtures\Attribute\Book'
              default_sorting_field: rank
              fields:
                title: { type: string, infix: true }
                tags: { type: 'string[]', property: tags, facet: true }
                tagSlugs: { type: 'string[]', property: tags.slug }
                summary: { type: string, options: { optional: true } }
                rank: { type: int32, property: rank }
                available: { type: bool, property: isAvailable, facet: true }
        YAML;

    private function configured(): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [Yaml::parse(self::YAML)['typesense']])['mappings']['book'];
    }

    public function testTheAttributesAreTheMappingTheConfigurationWouldHold(): void
    {
        [$name, $mapping] = AttributeReader::read(Book::class);

        $this->assertSame('book', $name);
        $configured = $this->configured();
        ksort($configured);
        ksort($mapping);
        $this->assertEquals($configured, $mapping);
    }

    public function testTheSchemaIsTheSameEitherWay(): void
    {
        $transformer = new EntityTransformer(EntityManagers::sqlite());

        $schema = fn (array $mapping) => array_map(fn ($f) => $f->toArray(), (new TypesenseMetadata('book', $mapping, $transformer))->getConfiguration()['fields'])
            + ['@' => array_diff_key((new TypesenseMetadata('book', $mapping, $transformer))->getConfiguration(), ['fields' => 1])];

        $fromAttributes = $schema(AttributeReader::read(Book::class)[1]);
        $this->assertSame($schema($this->configured()), $fromAttributes);
        $this->assertTrue($fromAttributes['title']['infix']);
        $this->assertTrue($fromAttributes['summary']['optional'], 'options go to the schema as they are');
        $this->assertArrayNotHasKey('optional', $fromAttributes['title']);
        $this->assertSame(['+', '-', '@', '.', ' '], $fromAttributes['@']['token_separators'], 'the configuration\'s defaults');
    }

    public function testAClassWithoutTheAttributeIsNoCollection(): void
    {
        $this->assertNull(AttributeReader::read(Tag::class));
    }

    public function testADirectoryIsScannedForItsDeclaredClassesOnly(): void
    {
        $mappings = AttributeReader::scan([\dirname(__DIR__, 2) . '/Fixtures/Attribute', '/does/not/exist']);

        $this->assertSame(['book'], array_keys($mappings));
        $this->assertSame(Book::class, $mappings['book']['class']);
    }
}
