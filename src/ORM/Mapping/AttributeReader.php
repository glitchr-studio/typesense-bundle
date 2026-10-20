<?php

declare(strict_types=1);

namespace Typesense\Bundle\ORM\Mapping;

use Typesense\Bundle\Attribute\Collection;
use Typesense\Bundle\Attribute\Field;

/**
 * Turns the attributes of Typesense\Bundle\Attribute into mappings - the
 * very arrays an entry of `typesense.mappings` is, so a class declared by
 * attributes and the same class declared in the configuration give the
 * same metadata and the same schema.
 */
final class AttributeReader
{
    /**
     * @param class-string $class
     *
     * @return ?array{0: string, 1: array<string, mixed>} the mapping key and the mapping, or null
     *                                                    when the class declares no Collection
     */
    public static function read(string $class): ?array
    {
        $reflection = new \ReflectionClass($class);
        $collection = ($reflection->getAttributes(Collection::class)[0] ?? null)?->newInstance();
        if (!$collection instanceof Collection) {
            return null;
        }
        if (str_contains($collection->name, '__')) {
            throw new \LogicException(sprintf('Collection name cannot contains `__` (%s on %s)', $collection->name, $class));
        }

        $fields = [];
        foreach ($reflection->getAttributes(Field::class) as $attribute) {
            $field = $attribute->newInstance();
            if (null === $field->name) {
                throw new \LogicException(sprintf('A field declared on the class %s needs a name', $class));
            }
            $fields[$field->name] = self::field($field, $field->property);
        }
        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes(Field::class) as $attribute) {
                $field = $attribute->newInstance();
                $fields[$field->name ?? $property->getName()] = self::field($field, $field->property ?? $property->getName());
            }
        }
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(Field::class) as $attribute) {
                $field = $attribute->newInstance();
                $name = lcfirst((string) preg_replace('/^(get|is|has)(?=[A-Z])/', '', $method->getName()));
                $fields[$field->name ?? $name] = self::field($field, $field->property ?? $method->getName());
            }
        }

        $mapping = ['class' => $class, 'fields' => $fields];
        if (null !== $collection->connection) {
            $mapping['connection'] = $collection->connection;
        }
        $mapping['default_sorting_field'] = $collection->defaultSortingField;
        $mapping['token_separators'] = $collection->tokenSeparators;
        $mapping['symbols_to_index'] = $collection->symbolsToIndex;

        return [$collection->name, $mapping];
    }

    /**
     * The mappings of every class declared under these directories with a
     * Collection attribute. A file is only loaded when it names the
     * attribute, so the rest of an application's code is never autoloaded.
     *
     * @param list<string> $directories
     *
     * @return array<string, array<string, mixed>> by mapping key
     */
    public static function scan(array $directories): array
    {
        $mappings = [];
        foreach (self::classes($directories, Collection::class) as $class) {
            if (null !== ($read = self::read($class))) {
                [$name, $mapping] = $read;
                if (isset($mappings[$name])) {
                    throw new \LogicException(sprintf('Collection "%s" is declared twice (%s and %s)', $name, $mappings[$name]['class'], $class));
                }
                $mappings[$name] = $mapping;
            }
        }
        ksort($mappings);

        return $mappings;
    }

    /**
     * The classes declared in the PHP files under $directories whose source
     * names $attribute's namespace (a `use` of it, or its full name), read
     * from their tokens.
     *
     * @param list<string> $directories
     *
     * @return list<class-string>
     */
    public static function classes(array $directories, string $attribute): array
    {
        $needle = substr($attribute, 0, (int) strrpos($attribute, '\\'));
        $classes = [];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (!str_contains($source, $needle)) {
                    continue;
                }
                foreach (self::declared($source) as $class) {
                    if (class_exists($class)) {
                        $classes[] = $class;
                    }
                }
            }
        }
        sort($classes);

        return array_values(array_unique($classes));
    }

    /** @return list<string> the classes a PHP source declares */
    private static function declared(string $source): array
    {
        $tokens = \PhpToken::tokenize($source);
        $namespace = '';
        $classes = [];
        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespace = '';
                for ($j = $i + 1; isset($tokens[$j]) && !$tokens[$j]->is([';', '{']); ++$j) {
                    if ($tokens[$j]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $namespace .= $tokens[$j]->text;
                    }
                }
            } elseif ($token->is(T_CLASS)) {
                for ($k = $i - 1; $k >= 0 && $tokens[$k]->is(T_WHITESPACE); --$k);
                if ($k >= 0 && $tokens[$k]->is([T_DOUBLE_COLON, T_NEW])) {
                    continue; // Foo::class, new class
                }
                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j]->is(T_WHITESPACE); ++$j);
                if (isset($tokens[$j]) && $tokens[$j]->is(T_STRING)) {
                    $classes[] = ltrim($namespace.'\\'.$tokens[$j]->text, '\\');
                }
            }
        }

        return $classes;
    }

    /** @return array<string, mixed> */
    private static function field(Field $field, ?string $property): array
    {
        $mapping = ['type' => $field->type];
        if (null !== $property) {
            $mapping['property'] = $property;
        }
        if ($field->facet) {
            $mapping['facet'] = true;
        }
        if ($field->infix) {
            $mapping['infix'] = true;
        }
        $mapping['options'] = $field->options;

        return $mapping;
    }
}
