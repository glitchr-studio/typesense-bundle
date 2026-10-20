<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\ORM\Mapping;

use PHPUnit\Framework\TestCase;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadataField;

class TypesenseMetadataFieldTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $field = new TypesenseMetadataField();

        $this->assertFalse($field->facet);
        $this->assertFalse($field->discriminator);
        $this->assertFalse($field->identifier);
    }

    public function testToArrayExposesAllProperties(): void
    {
        $field = new TypesenseMetadataField();
        $field->name = 'title';
        $field->type = 'string';
        $field->property = 'title';
        $field->identifier = true;

        $this->assertSame([
            'name' => 'title',
            'type' => 'string',
            'property' => 'title',
            'identifier' => true,
            'discriminator' => false,
            'facet' => false,
            'infix' => false,
        ], $field->toArray());
    }

    /**
     * An infix index is a property of the schema: Typesense builds it when
     * the collection is created, and an infix search on a field without one
     * finds nothing more. toArray() is what create() sends, so the flag must
     * be in it - it was read from the configuration and then dropped.
     */
    public function testInfixIsSentWithTheSchema(): void
    {
        $field = new TypesenseMetadataField();
        $field->name = 'title';
        $field->type = 'string';
        $field->infix = true;

        $this->assertTrue($field->toArray()['infix']);
    }

    /**
     * $property is a nullable typed property with no constructor default —
     * PHP's null-coalescing operators (??, ??=) are special-cased to treat
     * an uninitialized typed property as unset rather than throwing, so
     * toArray() must be safe to call even before ->property is ever
     * assigned (TypesenseMetadata's own constructor relies on exactly this
     * via `$field->property ??= ...`).
     */
    public function testToArrayIsSafeWithoutEverSettingProperty(): void
    {
        $field = new TypesenseMetadataField();
        $field->name = 'title';
        $field->type = 'string';

        $this->assertNull($field->toArray()['property']);
    }
}
