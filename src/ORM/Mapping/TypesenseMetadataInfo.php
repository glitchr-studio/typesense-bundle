<?php

declare(strict_types=1);

namespace Typesense\Bundle\ORM\Mapping;

class TypesenseMetadataInfo
{
    /**
     * @var string
     */
    public string $name;

    /**
     * @var string
     */
    public string $prefix = '';

    /**
     * @var ?string
     */
    public ?string $class;

    /**
     * @var array
     */
    public array $fields;

    /**
     * @var ?string
     */
    public ?string $defaultSortingField = null;

    /**
     * @var array
     */
    public array $symbolsToIndex = [];

    /**
     * @var array
     */
    public $tokenSeparators = [];
}
