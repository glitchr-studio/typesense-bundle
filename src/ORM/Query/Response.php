<?php

declare(strict_types=1);

namespace Typesense\Bundle\ORM\Query;

class Response
{
    protected mixed $facetCounts;
    protected mixed $found;
    protected mixed $hits;
    protected array $hydratedHits;
    protected bool $isHydrated;
    protected mixed $page;
    protected mixed $searchTimeMs;

    protected array $headers;
    protected int $status;

    public const MESSAGE  = 'message';

    public const FACETS_USE_INDEX = 0;
    public const FACETS_USE_KEY   = 1;
    
    public function __construct(?array $result, int $status = 200, array $headers = [])
    {
        $this->facetCounts = $result['facet_counts'] ?? null;

        $this->found = $result['found'] ?? null;
        $this->hits = $result['hits'] ?? null;
        $this->page = $result['page'] ?? null;
        $this->searchTimeMs = $result['search_time_ms'] ?? null;
        $this->isHydrated = false;
        $this->hydratedHits = [];

        $this->status = $status;
        $this->headers = $headers;
    }

    public function getStatus(): int
    {
        if (0 == $this->status) {
            return $this->getFound() ? 200 : ($this->getContent() ? 500 : 404);
        }

        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $key)
    {
        return $this->headers[$key] ?? null;
    }

    public function getContent(): string
    {
        return $this->getHeader(self::MESSAGE) ?? '';
    }

    /**
     * Get the value of facetCounts.
     */
    public function getFacetCounts(array $checkbox = [], bool $sortByName = false, ?int $mode = self::FACETS_USE_INDEX)
    {
        $facetCounts = $this->facetCounts ?? []; // array clone

        // Sort facet by alphabetic order instead of count frequency 
        if($sortByName) {

            foreach($facetCounts as &$facetCount) {
                $facetCount['counts'] ??= [];
                $facetCountCounts = &$facetCount['counts'];
                usort($facetCountCounts, fn ($f1, $f2) => strcmp((string) ($f1['value'] ?? ''), (string) ($f2['value'] ?? '')));
            }
        }

        // Mark as checked if `facet_by` list provided
        foreach($facetCounts as &$facetCount) {

            $counts = &$facetCount["counts"];
            $fieldName = $facetCount["field_name"];

            foreach($counts as &$count) {
                $count["checked"] = in_array($count["value"], $checkbox[$fieldName] ?? []);
            } 
        }

        // Replace numerical indexes by associative keys
        if($mode == self::FACETS_USE_KEY) {

            $facetCounts = array_column($facetCounts, null, 'field_name');
            foreach($facetCounts as &$facetCount) {
                $facetCount["counts"] = array_column($facetCount["counts"], null, 'value');
            }
        }

        return $facetCounts;
    }

    /**
     * @return mixed|null
     */
    public function getHits()
    {
         return $this->hits;
    }

    /**
     * @return mixed|null
     */
    public function getHit(mixed $hydratedHit): ?array
    {
        // Strict: two distinct entities with equal property values (e.g.
        // two transient, not-yet-persisted objects) must not collide.
        $hitIndex = array_search($hydratedHit, $this->hydratedHits, true);
        if($hitIndex === false) return null;
        
        return $this->hits[$hitIndex] ?? null;
    }

    /**
     * @return mixed|null
     */
    public function getRawResults()
    {
        return $this->hits;
    }

    /**
     * Get the value of hits.
     */
    public function getResults()
    {
        if ($this->isHydrated) {
            return $this->hydratedHits;
        }

        return $this->getRawResults();
    }

    /**
     * Get the value of page.
     */
    public function getPage()
    {
        return $this->page;
    }

    /**
     * Get total hits.
     */
    public function getFound()
    {
        return $this->found;
    }

    /**
     * Set the value of hydratedHits.
     */
    public function setHydratedHits($hydratedHits): self
    {
        $this->hydratedHits = $hydratedHits;

        return $this;
    }

    /**
     * Set the value of isHydrated.
     */
    public function setHydrated(bool $isHydrated): self
    {
        $this->isHydrated = $isHydrated;

        return $this;
    }
}
