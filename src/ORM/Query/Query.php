<?php

declare(strict_types=1);

namespace Typesense\Bundle\ORM\Query;

/**
 * Advanced request including additional options
 */
class Query extends Request
{
    public const INFIX_OFF = 'off';
    public const INFIX_ALWAYS = 'always';
    public const INFIX_FALLBACK = 'fallback';

    private const INFIX_ALLOWED_VALUES = [
        self::INFIX_OFF,
        self::INFIX_ALWAYS,
        self::INFIX_FALLBACK,
    ];

    public const MAX_HITS = 'max_hits';
    public const SORT_BY = 'sort_by';
    public const FACET_BY = 'facet_by';
    public const INSTANCE_OF = 'instance_of';
    public const FILTER_BY = 'filter_by';
    public const PREFIX = 'prefix';

    public const FACET_QUERY = 'facet_query';
    public const NUM_TYPOS = 'num_typos';
    public const PAGE = 'page';
    public const PER_PAGE = 'per_page';
    public const GROUP_BY = 'group_by';
    public const GROUP_LIMIT = 'group_limit';
    public const INCLUDE_FIELDS = 'include_fields';
    public const EXCLUDE_FIELDS = 'exclude_fields';
    public const HIGHLIGHT_FULL_FIELDS = 'highlight_full_fields';
    public const SNIPPET_THRESHOLD = 'snippet_threshold';
    public const DROP_TOKENS_THRESHOLD = 'drop_tokens_threshold';
    public const TYPE_TOKENS_THRESHOLD = 'typo_tokens_threshold';
    public const PINNED_HITS = 'pinned_hits';
    public const HIDDEN_HITS = 'hidden_hits';
    public const INFIX = 'infix';
    public const MAX_FACET_VALUES = 'max_facet_values';

    /**
     * Maximum number of hits returned. Increasing this value might increase search latency. Use all to return all hits found.
     *
     * @param [type] $maxHits
     */
    public function maxHits($maxHits): self
    {
        return $this->addHeader(self::MAX_HITS, $maxHits);
    }

    /**
     * Boolean field to indicate that the last word in the query should be treated as a prefix, and not as a whole word. This is necessary for building autocomplete and instant search interfaces.
     */
    public function prefix(bool $prefix): self
    {
        return $this->addHeader(self::PREFIX, $prefix);
    }

    /**
     * Filter conditions for refining your search results. A field can be matched against one or more values.
     */
    public function filterBy(string $filterBy): self
    {
        return $this->addHeader(self::FILTER_BY, $filterBy);
    }

    public function addFilterBy(string $filterBy): self
    {
        $_filterBy = $this->getHeader(self::FILTER_BY);
        $filterBy = $_filterBy ? trim($_filterBy . ' && ' . $filterBy, ' &') : $filterBy;

        return $this->addHeader(self::FILTER_BY, $filterBy);
    }

    public function notInstanceOf(string $class): self
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException('Class "' . $class . "\" is doesn't exists");
        }

        $_discriminateBy = $this->getHeader(self::INSTANCE_OF);
        $discriminateBy = $_discriminateBy ? trim($_discriminateBy . ', ^' . $class, ' ,') : '^' . $class;

        return $this->addHeader(self::INSTANCE_OF, $discriminateBy);
    }

    public function instanceOf(string $class): self
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException('Class "' . $class . "\" is doesn't exists");
        }

        $_discriminateBy = $this->getHeader(self::INSTANCE_OF);
        $discriminateBy = $_discriminateBy ? trim($_discriminateBy . ', ' . $class, ' ,') : $class;

        return $this->addHeader(self::INSTANCE_OF, $discriminateBy);
    }

    /**
     * A list of numerical fields and their corresponding sort orders that will be used for ordering your results. Separate multiple fields with a comma. Upto 3 sort fields can be specified.
     */
    public function sortBy(string $sortBy): self
    {
        return $this->addHeader(self::SORT_BY, $sortBy);
    }

    /**
     * A list of fields that will be used for faceting your results on. Separate multiple fields with a comma.
     */
    public function facetBy(array|string $facetBy): self
    {
        $facetBy = $this->normalizeString($facetBy);
        return $this->addHeader(self::FACET_BY, $facetBy);
    }

    private function normalizeString(array|string $value): string
    {
        $value = is_array($value) ? implode(',', $value) : $value;
        $fields = explode(',', $value);
        $numFields = $this->getNumFields();
        
        while (count($fields) < $numFields) {
            $fields = array_merge($fields, $fields);
        }
        
        return implode(',', array_slice($fields, 0, $numFields));
    }

    /**
     * Maximum number of facet values to be returned.
     */
    public function maxFacetValues(int $maxFacetValues): self
    {
        return $this->addHeader(self::MAX_FACET_VALUES, $maxFacetValues);
    }

    /**
     * Facet values that are returned can now be filtered via this parameter. The matching facet text is also highlighted. For example, when faceting by category, you can set facet_query=category:shoe to return only facet values that contain the prefix "shoe".
     */
    public function facetQuery(string $facetQuery): self
    {
        return $this->addHeader(self::FACET_QUERY, $facetQuery);
    }

    /**
     * Number of typographical errors (1 or 2) that would be tolerated.
     */
    public function numTypos(int $numTypos): self
    {
        return $this->addHeader(self::NUM_TYPOS, $numTypos);
    }

    /**
     * Results from this specific page number would be fetched.
     */
    public function page(int $page): self
    {
        return $this->addHeader(self::PAGE, $page);
    }

    /**
     * Number of results to fetch per page.
     */
    public function perPage(int $perPage): self
    {
        return $this->addHeader(self::PER_PAGE, $perPage);
    }

    /**
     * You can aggregate search results into groups or buckets by specify one or more group_by fields. Separate multiple fields with a comma.
     */
    public function groupBy(string $groupBy): self
    {
        return $this->addHeader(self::GROUP_BY, $groupBy);
    }

    /**
     * Maximum number of hits to be returned for every group. If the group_limit is set as K then only the top K hits in each group are returned in the response.
     */
    public function groupLimit(int $groupLimit): self
    {
        return $this->addHeader(self::GROUP_LIMIT, $groupLimit);
    }

    /**
     * Comma-separated list of fields from the document to include in the search result.
     */
    public function includeFields(string $includeFields): self
    {
        return $this->addHeader(self::INCLUDE_FIELDS, $includeFields);
    }

    /**
     * Comma-separated list of fields from the document to exclude in the search result.
     */
    public function excludeFields(string $excludeFields): self
    {
        return $this->addHeader(self::EXCLUDE_FIELDS, $excludeFields);
    }

    /**
     * Comma separated list of fields which should be highlighted fully without snippeting.
     */
    public function highlightFullFields(string $highlightFullFields): self
    {
        return $this->addHeader(self::HIGHLIGHT_FULL_FIELDS, $highlightFullFields);
    }

    /**
     * Field values under this length will be fully highlighted, instead of showing a snippet of relevant portion.
     */
    public function snippetThreshold(int $snippetThreshold): self
    {
        return $this->addHeader(self::SNIPPET_THRESHOLD, $snippetThreshold);
    }

    /**
     * If the number of results found for a specific query is less than this number, Typesense will attempt to drop the tokens in the query until enough results are found. Tokens that have the least individual hits are dropped first. Set drop_tokens_threshold to 0 to disable dropping of tokens.
     */
    public function dropTokensThreshold(int $dropTokensThreshold): self
    {
        return $this->addHeader(self::DROP_TOKENS_THRESHOLD, $dropTokensThreshold);
    }

    /**
     * If the number of results found for a specific query is less than this number, Typesense will attempt to look for tokens with more typos until enough results are found.
     */
    public function typoTokensThreshold(int $typoTokensThreshold): self
    {
        return $this->addHeader(self::TYPE_TOKENS_THRESHOLD, $typoTokensThreshold);
    }

    /**
     * A list of records to unconditionally include in the search results at specific positions.
     * An example use case would be to feature or promote certain items on the top of search results.
     * A comma separated list of record_id:hit_position. Eg: to include a record with ID 123 at Position 1 and another record with ID 456 at Position 5, you'd specify 123:1,456:5.
     * You could also use the Overrides feature to override search results based on rules. Overrides are applied first, followed by pinned_hits and finally hidden_hits.
     */
    public function pinnedHits(string $pinnedHits): self
    {
        return $this->addHeader(self::PINNED_HITS, $pinnedHits);
    }

    /**
     * A list of records to unconditionally hide from search results.
     * A comma separated list of record_ids to hide. Eg: to hide records with IDs 123 and 456, you'd specify 123,456.
     * You could also use the Overrides feature to override search results based on rules. Overrides are applied first, followed by pinned_hits and finally hidden_hits.
     */
    public function hiddenHits(string $hiddenHits): self
    {
        return $this->addHeader(self::HIDDEN_HITS, $hiddenHits);
    }

    /**
     * If infix index is enabled for this field, infix searching can be done on a per-field basis by
     * sending a comma separated string parameter called infix to the search query.
     *
     * This parameter can have 3 values:
     *
     * off: infix search is disabled, which is default
     * always: infix search is performed along with regular search
     * fallback: infix search is performed if regular search does not produce results
     */
    public function infix(string $infix): self
    {
        // One value per query_by field: "always" for three fields is sent as
        // "always,always,always", and each of them is checked.
        $infix = $this->normalizeString($infix);
        foreach (explode(',', $infix) as $value) {
            if (!in_array(trim($value), self::INFIX_ALLOWED_VALUES, true)) {
                return $this;
            }
        }

        return $this->addHeader(self::INFIX, $infix);
    }
}
