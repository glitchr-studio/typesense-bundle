<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Fixtures\Attribute;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as Collections;
use Doctrine\ORM\Mapping as ORM;
use Typesense\Bundle\Attribute\Collection;
use Typesense\Bundle\Attribute\Field;

/**
 * An entity that declares its collection by attributes and implements
 * nothing of the bundle: its fields are read by PropertyReader, its tags
 * turned into strings by their __toString().
 */
#[ORM\Entity]
#[ORM\Table(name: 'book')]
#[Collection('book', defaultSortingField: 'rank')]
#[Field('title', type: 'string', infix: true)]
#[Field('tags', type: 'string[]', property: 'tags', facet: true)]
#[Field('tagSlugs', type: 'string[]', property: 'tags.slug')]
#[Field('summary', type: 'string', options: ['optional' => true])]
class Book
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private string $title;

    #[ORM\Column]
    #[Field(type: 'int32')]
    private int $rank;

    #[ORM\Column(nullable: true)]
    private ?string $summary = null;

    /** @var Collections<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class, cascade: ['persist'])]
    private Collections $tags;

    public function __construct(string $title, int $rank, Tag ...$tags)
    {
        $this->title = $title;
        $this->rank = $rank;
        $this->tags = new ArrayCollection($tags);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /** @return Collections<int, Tag> */
    public function getTags(): Collections
    {
        return $this->tags;
    }

    #[Field('available', type: 'bool', facet: true)]
    public function isAvailable(): bool
    {
        return $this->rank > 0;
    }
}
