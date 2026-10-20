<?php

declare(strict_types=1);

namespace Typesense\Bundle\ORM\Transformer;

use Symfony\Component\PropertyAccess\Exception\RuntimeException;
use Typesense\Bundle\ORM\Mapping\TypesenseMetadata;
use Typesense\Bundle\ORM\Transformer\Abstract\AbstractTransformer;
use Typesense\Bundle\TypesenseInterface;

class EntityTransformer extends AbstractTransformer
{
    protected function get_class_implementing(object|string $entityOrClass, string $interface): ?string
    {
        if (!interface_exists($interface)) {
            throw new \InvalidArgumentException(sprintf('Interface "%s" does not exist.', $interface));
        }

        $class = is_object($entityOrClass) ? get_class($entityOrClass) : ltrim($entityOrClass, '\\');

        while ($class) {
            $currentInterfaces = class_implements($class) ?: [];
            $parent            = get_parent_class($class);
            $parentInterfaces  = $parent ? (class_implements($parent) ?? []) : [];

            // Which interfaces were introduced at this level?
            $introduced = array_diff($currentInterfaces, $parentInterfaces);

            if (in_array($interface, $introduced, true)) {
                return $class;
            }

            $class = $parent;
        }

        return null;
    }

    /**
     * The mapping an object is converted by: the one of the class that
     * introduced TypesenseInterface when the object implements it (as
     * before 2.0), otherwise the mapped class nearest the root of its
     * hierarchy (a discriminator sub-class is converted by its root
     * collection's mapping, as with the interface).
     */
    public function mappedClass(object $entity): ?string
    {
        if ($entity instanceof TypesenseInterface) {
            return $this->get_class_implementing($entity, TypesenseInterface::class);
        }

        return $this->getRootMapping($entity::class)?->getClass();
    }

    public function convert(object $entity): array
    {
        $entityClass = $this->mappedClass($entity);
        if (!$entityClass || !$this->getMapping($entityClass) instanceof TypesenseMetadata) {
            throw new \Exception(sprintf('Class %s is not supported for Doctrine To Typesense Transformation', $entity::class));
        }

        $data = [];

        $fields = $this->getMapping($entityClass)->fields;
        foreach ($fields as $key => $field) {
            try {
                if ($field->discriminator) {
                    $value = $this->objectManager->getClassMetadata(get_class($entity))->discriminatorValue;
                } elseif ($field->identifier && $field->property && $this->objectManager->getClassMetadata($entity::class)->hasField($field->property)) {
                    // The identifier as Doctrine holds it, getter or not.
                    $value = $this->objectManager->getClassMetadata($entity::class)->getFieldValue($entity, $field->property);
                } else {
                    $path = $field->property ?? $field->name ?? $key;
                    $value = $entity instanceof TypesenseInterface
                        ? $entity->__typesenseGetter($path, $field->toArray())
                        : PropertyReader::read($entity, $path, $field->toArray());
                }
            } catch (RuntimeException $exception) {
                $value = null;
            }

            $name = $field->name ?? $key;

            $data[$name] = $this->get($entityClass, $name, $value);
        }

        return $data;
    }

    /**
     * @param string $objectClass
     * @param string $propertyName
     * @param $value
     * @return array|int|string
     */
    public function get(string $objectClass, string $propertyName, $value)
    {
        $metadata = $this->getMapping($objectClass);
        $metadata->fields[$propertyName]->name ??= $propertyName;

        $key = array_search(
            $propertyName,
            array_column(
                $metadata->fields,
                'name'
            ),
            true
        );

        $fields = array_values($metadata->fields);
        $originalType = $fields[$key]->type;
        $castedType = $this->cast($originalType);

        switch ($originalType . ':' . $castedType) {
            case self::TYPE_DATETIME . ':' . self::TYPE_INT64:
                if ($value instanceof \DateTimeInterface) {
                    return $value->getTimestamp();
                }

                return 0;

            case self::TYPE_OBJECT . ':' . self::TYPE_STRING:
                return $value->__toString();

            case self::TYPE_COLLECTION . ':' . self::TYPE_STRING_ARRAY:
                return array_filter(array_values(
                    $value->map(function ($v) {
                        return $v->__toString();
                    })->toArray()
                )) ?? [];

            case self::TYPE_STRING . ':' . self::TYPE_STRING:
            case self::TYPE_TEXT . ':' . self::TYPE_STRING:
                return (string)$value;

            default:
                return is_array($value) ? array_values(array_filter($value)) : $value;
        }
    }
}
