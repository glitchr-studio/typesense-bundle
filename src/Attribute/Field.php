<?php

declare(strict_types=1);

namespace Typesense\Bundle\Attribute;

/**
 * A field of the class's collection. On the class, it names the field and
 * the property path it is read from (`translations.title`, `tags.slug`,
 * `isAudible`); on a property or a method, the name and the path default to
 * that property's (a method's name without get/is/has).
 *
 * The type is Typesense's (string, string[], int32, int64, float, bool,
 * geopoint...) or one the transformer converts (datetime: a timestamp;
 * collection: string[]; object, text: string). $options go to the schema
 * as they are (sort, optional, locale, stem...).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Field
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly string $type = 'string',
        public readonly ?string $property = null,
        public readonly bool $facet = false,
        public readonly bool $infix = false,
        public readonly array $options = [],
    ) {
    }
}
