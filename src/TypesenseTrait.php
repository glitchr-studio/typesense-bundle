<?php

namespace Typesense\Bundle;

use Typesense\Bundle\ORM\Transformer\PropertyReader;

/**
 * @deprecated since 2.0: an entity needs neither this trait nor
 * TypesenseInterface to be indexed - a class named by a mapping (the
 * configuration's `typesense.mappings`, or the attributes of
 * Typesense\Bundle\Attribute) is read by ORM\Transformer\PropertyReader,
 * which does what this trait does. Kept working for the entities that use it.
 */
trait TypesenseTrait
{
    public function __typesenseGetter(string $propertyName, array $propertyInfo): mixed
    {
        return PropertyReader::read($this, $propertyName, $propertyInfo);
    }
}
