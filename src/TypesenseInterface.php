<?php

namespace Typesense\Bundle;

/**
 * An object that says how it is read into a document (__typesenseGetter())
 * and what string it is inside another's (__typesense()).
 *
 * Optional since 2.0: a class named by a mapping is indexed without it,
 * its fields read by ORM\Transformer\PropertyReader and its related objects
 * turned into strings by __toString(). An object that implements it is
 * still read through it.
 */
interface TypesenseInterface
{
    public function __typesense(): ?string;

    public function __typesenseGetter(string $propertyName, array $propertyInfo): mixed;
}
