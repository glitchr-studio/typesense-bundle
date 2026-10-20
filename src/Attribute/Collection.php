<?php

declare(strict_types=1);

namespace Typesense\Bundle\Attribute;

/**
 * A class indexed in a Typesense collection - what an entry of the
 * configuration's `typesense.mappings` says, said on the class itself:
 *
 *     #[Collection('article', defaultSortingField: 'publishedAt')]
 *     #[Field('title', type: 'string[]', property: 'translations.title', infix: true)]
 *     class Article { ... }
 *
 * The defaults are the configuration's. The identifier and Doctrine's
 * discriminator are added by the metadata, as for a mapping; a
 * discriminator's sub-classes get their own collections (article__<value>).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Collection
{
    /**
     * @param string       $name                the mapping key (the collection's name on the server,
     *                                          behind the collection prefix); no "__"
     * @param ?string      $connection          the connection, the default one when null
     * @param list<string> $tokenSeparators
     * @param list<string> $symbolsToIndex
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $connection = null,
        public readonly ?string $defaultSortingField = null,
        public readonly array $tokenSeparators = ['+', '-', '@', '.', ' '],
        public readonly array $symbolsToIndex = ['+'],
    ) {
    }
}
