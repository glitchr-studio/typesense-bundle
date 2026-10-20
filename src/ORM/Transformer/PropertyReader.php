<?php

declare(strict_types=1);

namespace Typesense\Bundle\ORM\Transformer;

use Doctrine\Common\Collections\Collection;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Typesense\Bundle\TypesenseInterface;

/**
 * Reads a field's value from an object by its property path, as a document
 * of a collection holds it - what TypesenseTrait::__typesenseGetter() did
 * inside each entity, now done from outside, so an entity declares what is
 * indexed (a mapping in the configuration or attributes) and implements
 * nothing:
 *
 * - "translations.title" walks the path; through a collection, every item
 *   is read ("tags.slug" is the slugs of all the tags);
 * - a collection at the end becomes an array, each object in it a string:
 *   __typesense() for a TypesenseInterface, __toString() otherwise;
 * - an array field ("string[]", or any value that is an array) is
 *   flattened; a single value of an array field is wrapped.
 */
final class PropertyReader
{
    private static ?PropertyAccessorInterface $accessor = null;

    /**
     * @param array{type?: string} $field the field's description (its type at least)
     */
    public static function read(object $object, string $path, array $field = []): mixed
    {
        $accessor = self::$accessor ??= PropertyAccess::createPropertyAccessorBuilder()->enableMagicCall()->getPropertyAccessor();

        $value = $object;
        foreach (explode('.', $path) as $property) {
            if (!$value) {
                continue;
            }
            $value = $value instanceof Collection
                ? $value->map(fn ($v) => $accessor->getValue($v, $property))
                : $accessor->getValue($value, $property);
        }

        if ($value instanceof Collection) {
            $value = $value->map(function ($v) {
                if ($v instanceof TypesenseInterface) {
                    return $v->__typesense();
                }
                if (\is_object($v) && method_exists($v, '__toString')) {
                    return $v->__toString();
                }

                return $v;
            })->toArray();
        }

        $value = str_ends_with((string) ($field['type'] ?? ''), '[]') && !\is_array($value) ? [$value] : $value;
        if (\is_array($value)) {
            $flat = [];
            array_walk_recursive($value, function ($a) use (&$flat) {
                $flat[] = $a;
            });

            return $flat;
        }

        return $value;
    }
}
