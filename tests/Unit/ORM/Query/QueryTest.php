<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\ORM\Query;

use PHPUnit\Framework\TestCase;
use Typesense\Bundle\ORM\Query\Query;

/**
 * Dummy classes used purely to exercise instanceOf()/notInstanceOf()'s
 * class_exists() guard — Query has no idea what these represent, it just
 * needs a real, loadable class name.
 */
class QueryTestFixtureA
{
}
class QueryTestFixtureB
{
}

class QueryTest extends TestCase
{
    public function testFluentSettersStoreHeaders(): void
    {
        $query = (new Query('title'))
            ->maxHits(100)
            ->prefix(true)
            ->filterBy('state:=published')
            ->sortBy('publishedAt:desc')
            ->maxFacetValues(10)
            ->facetQuery('category:shoe')
            ->numTypos(1)
            ->page(2)
            ->perPage(20)
            ->groupBy('category')
            ->groupLimit(3)
            ->includeFields('id,title')
            ->excludeFields('content')
            ->highlightFullFields('title')
            ->snippetThreshold(30)
            ->dropTokensThreshold(1)
            ->typoTokensThreshold(1)
            ->pinnedHits('123:1')
            ->hiddenHits('456');

        $this->assertSame(100, $query->getHeader(Query::MAX_HITS));
        $this->assertTrue($query->getHeader(Query::PREFIX));
        $this->assertSame('state:=published', $query->getHeader(Query::FILTER_BY));
        $this->assertSame('publishedAt:desc', $query->getHeader(Query::SORT_BY));
        $this->assertSame(10, $query->getHeader(Query::MAX_FACET_VALUES));
        $this->assertSame('category:shoe', $query->getHeader(Query::FACET_QUERY));
        $this->assertSame(1, $query->getHeader(Query::NUM_TYPOS));
        $this->assertSame(2, $query->getHeader(Query::PAGE));
        $this->assertSame(20, $query->getHeader(Query::PER_PAGE));
        $this->assertSame('category', $query->getHeader(Query::GROUP_BY));
        $this->assertSame(3, $query->getHeader(Query::GROUP_LIMIT));
        $this->assertSame('id,title', $query->getHeader(Query::INCLUDE_FIELDS));
        $this->assertSame('content', $query->getHeader(Query::EXCLUDE_FIELDS));
        $this->assertSame('title', $query->getHeader(Query::HIGHLIGHT_FULL_FIELDS));
        $this->assertSame(30, $query->getHeader(Query::SNIPPET_THRESHOLD));
        $this->assertSame(1, $query->getHeader(Query::DROP_TOKENS_THRESHOLD));
        $this->assertSame(1, $query->getHeader(Query::TYPE_TOKENS_THRESHOLD));
        $this->assertSame('123:1', $query->getHeader(Query::PINNED_HITS));
        $this->assertSame('456', $query->getHeader(Query::HIDDEN_HITS));
    }

    public function testAddFilterByCombinesConditionsWithAnd(): void
    {
        $query = (new Query('title'))->filterBy('state:=published');
        $query->addFilterBy('destination:=paris');

        $this->assertSame('state:=published && destination:=paris', $query->getHeader(Query::FILTER_BY));
    }

    public function testAddFilterByOnAnUnsetFilterBySetsItDirectly(): void
    {
        $query = new Query('title');
        $query->addFilterBy('state:=published');

        $this->assertSame('state:=published', $query->getHeader(Query::FILTER_BY));
    }

    public function testInstanceOfAddsClassesCommaSeparated(): void
    {
        $query = new Query('title');
        $query->instanceOf(QueryTestFixtureA::class);
        $query->instanceOf(QueryTestFixtureB::class);

        $this->assertSame(
            QueryTestFixtureA::class . ', ' . QueryTestFixtureB::class,
            $query->getHeader(Query::INSTANCE_OF)
        );
    }

    public function testNotInstanceOfPrefixesWithACaret(): void
    {
        $query = new Query('title');
        $query->notInstanceOf(QueryTestFixtureA::class);

        $this->assertSame('^' . QueryTestFixtureA::class, $query->getHeader(Query::INSTANCE_OF));
    }

    public function testInstanceOfRejectsANonExistentClass(): void
    {
        $query = new Query('title');

        $this->expectException(\InvalidArgumentException::class);

        $query->instanceOf('Totally\\Bogus\\ClassName');
    }

    public function testNotInstanceOfRejectsANonExistentClass(): void
    {
        $query = new Query('title');

        $this->expectException(\InvalidArgumentException::class);

        $query->notInstanceOf('Totally\\Bogus\\ClassName');
    }

    /**
     * facetBy()/infix() run their value through normalizeString(), which
     * pads/repeats the input to match getNumFields() (the number of
     * query_by fields) — a single facet field gets repeated once per
     * query_by field.
     */
    public function testFacetByIsPaddedToMatchTheNumberOfQueryByFields(): void
    {
        $query = new Query(['title', 'content']);
        $query->facetBy('category');

        $this->assertSame('category,category', $query->getHeader(Query::FACET_BY));
    }

    public function testFacetByWithEnoughFieldsIsTruncatedNotPadded(): void
    {
        $query = new Query(['title', 'content']);
        $query->facetBy(['category', 'state', 'tags']);

        $this->assertSame('category,state', $query->getHeader(Query::FACET_BY));
    }

    public function testInfixOnlyAcceptsTheDocumentedValues(): void
    {
        $query = new Query('title');
        $query->infix(Query::INFIX_ALWAYS);

        $this->assertSame(Query::INFIX_ALWAYS, $query->getHeader(Query::INFIX));
    }

    public function testInfixSilentlyIgnoresAnInvalidValue(): void
    {
        $query = new Query('title');
        $query->infix('not-a-real-value');

        $this->assertFalse($query->hasHeader(Query::INFIX));
    }

    /**
     * Typesense takes one infix mode per query_by field. infix() pads the
     * value like facetBy() - "always" for three fields is
     * "always,always,always" - and used to compare that whole string with
     * the three allowed values: with more than one query_by field, infix
     * was silently never sent.
     */
    public function testInfixIsGivenOncePerQueryByField(): void
    {
        $query = new Query('title, excerpt, content', 'fuj');
        $query->infix(Query::INFIX_ALWAYS);

        $this->assertSame('always,always,always', $query->getHeader(Query::INFIX));
    }

    public function testInfixTakesOneModePerField(): void
    {
        $query = new Query(['title', 'content']);
        $query->infix('always,off');

        $this->assertSame('always,off', $query->getHeader(Query::INFIX));
    }

    public function testInfixIgnoresAListWithAnInvalidMode(): void
    {
        $query = new Query(['title', 'content']);
        $query->infix('always,sometimes');

        $this->assertFalse($query->hasHeader(Query::INFIX));
    }
}
