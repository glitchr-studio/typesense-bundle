<?php

declare(strict_types=1);

namespace Typesense\Bundle\ORM\Mapping;

class TypesenseMetadataField
{
    public string $name;

    public string $type;

    public bool $identifier;

    public bool $facet;

    /**
     * An infix index on the field (Typesense's `infix: true`): what lets a
     * search ask for `infix=always|fallback` on it. Sent with the schema.
     */
    public bool $infix;

    /**
     * @var ?string
     */
    public ?string $property;

    public bool $discriminator;

    /**
     * Further parameters of the field's schema, sent as they are (sort,
     * optional, locale, stem...); none by default.
     *
     * @var array<string, mixed>
     */
    public array $options = [];

    public function __construct()
    {
        $this->facet = false;
        $this->infix = false;
        $this->discriminator = false;
        $this->identifier = false;
        $this->property = null;
    }

    public function toArray(): array
    {
        $configuration = [];
        $configuration['name'] = $this->name;
        $configuration['type'] = $this->type;
        $configuration['property'] = $this->property;
        $configuration['identifier'] = $this->identifier;
        $configuration['discriminator'] = $this->discriminator;
        $configuration['facet'] = $this->facet;
        $configuration['infix'] = $this->infix;

        return $this->options ? $configuration + $this->options : $configuration;
    }
}
