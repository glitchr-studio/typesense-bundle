# Getting started

A minimal, working setup: one entity, one collection, a multi-server
connection config. This reflects what the bundle actually implements today —
see the note at the bottom about `docs/cookbook/autocomplete.md`, which
describes a feature that doesn't exist in the current code.

## 1. Multiple servers ("multi server")

Every connection is independent — point different collections at different
Typesense clusters (e.g. a small always-hot cluster for autocomplete-style
collections, a bigger one for full-text search), or just run one.

```yaml
# config/packages/typesense.yaml
typesense:
    default_connection: default

    connections:
        default:
            secret: '%env(TYPESENSE_SECRET)%'
            scheme: '%env(TYPESENSE_SCHEME)%'
            host: '%env(TYPESENSE_HOST)%'
            port: '%env(int:TYPESENSE_PORT)%'
            options:
                connection_timeout_seconds: 2

        analytics:
            secret: '%env(TYPESENSE_ANALYTICS_SECRET)%'
            scheme: '%env(TYPESENSE_ANALYTICS_SCHEME)%'
            host: '%env(TYPESENSE_ANALYTICS_HOST)%'
            port: '%env(int:TYPESENSE_ANALYTICS_PORT)%'
```

## 2. Map an entity to a collection

On the entity itself, with the attributes of `Typesense\Bundle\Attribute`
(the classes under `src/` are read; `typesense.attributes` names other
directories, `[]` none):

```php
<?php
// src/Entity/Article.php
namespace App\Entity;

use Typesense\Bundle\Attribute\Collection;
use Typesense\Bundle\Attribute\Field;

#[Collection('article', defaultSortingField: 'publishedAt')]
#[Field('title', type: 'string', infix: true)]            // infix search on it
#[Field('category', type: 'string', facet: true)]         // filterable/facetable
#[Field('tags', type: 'string[]', property: 'tags.slug')] // nested property path
class Article
{
    // ... id, title, category, tags, as normal Doctrine mapping ...

    #[Field(type: 'datetime')]   // on a property: its name and path
    private ?\DateTimeInterface $publishedAt = null;
}
```

Or in the configuration - the same mapping, key by key (for a key in both,
the configuration wins):

```yaml
# config/packages/typesense.yaml (continued)
typesense:
    mappings:
        article:
            connection: default
            class: 'App\Entity\Article'
            default_sorting_field: publishedAt

            fields:
                title:
                    type: string
                    infix: true
                category:
                    type: string
                    facet: true
                tags:
                    property: tags.slug
                    type: string[]
                publishedAt:
                    type: datetime
```

A field's `options` (`sort`, `optional`, `locale`, `stem`...) go to the
schema as they are.

## 3. The entity implements nothing

A class named by a mapping is indexed as it is: each field is read by its
property path (`ORM\Transformer\PropertyReader`: through collections, a
collection's objects by their `__toString()`), the identifier and Doctrine's
discriminator are added by the metadata, each discriminator value gets a
sub-collection (`article__<value>`).

`TypesenseInterface` and `TypesenseTrait` still work for the entities that
use them (an object that implements the interface is read through its
`__typesenseGetter()`, and is `__typesense()` inside another's collection),
and are no longer needed.

Nothing else to wire manually: `TypesenseIndexer` (a Doctrine
`postPersist`/`postUpdate`/`preRemove`/`postRemove`/`postFlush` listener) keeps
the Typesense collection in sync automatically whenever an `Article` is
persisted, updated, or removed through Doctrine.

## 4. Create the collection and backfill existing rows

```bash
# Drops and recreates the collection of every configured mapping — and only
# those: whatever else lives on the server is left alone.
bin/console typesense:create

# Backfills every existing Article row into the (now-empty) collection.
bin/console typesense:action upsert
```

## 4b. Several environments on one server

A collection is named after its mapping key (`article` above). When dev,
test or a demo share one Typesense server, give each its own
`collection_prefix` — the mapping, the finder service
(`typesense.finder.article`) and `getFinder('article')` stay the same, only
the collection's name on the server changes:

```yaml
# config/packages/typesense.yaml (continued)
when@test:
    typesense:
        collection_prefix: 'test_'    # "test_article" on the server

when@demo:
    typesense:
        collection_prefix: 'demo_'    # "demo_article" on the server
```

## 5. Search

```php
use Typesense\Bundle\ORM\Query\Query;
use Typesense\Bundle\ORM\TypesenseManager;

class ArticleSearchController
{
    public function __construct(private TypesenseManager $typesenseManager) {}

    public function search(string $term)
    {
        $finder = $this->typesenseManager->getFinder('article');

        $query = new Query('title,tags');
        $query->q($term)->filterBy('category:=electronics')->perPage(20);

        $response = $finder->query($query); // Response, hydrated back to real Article entities

        return $response->getResults();
    }
}
```

## Multiple Typesense servers in the same request

Since every `Connection`/`TypesenseCollection`/`TypesenseFinder` is a normal
autowired service, mixing servers is just autowiring two different
collections that happen to be configured against different `connection:`
names — there's no special API for it:

```php
$mainResults = $this->typesenseManager->getFinder('article')->query($query);
$analyticsResults = $this->typesenseManager->getFinder('event_log')->query($query);
```

## A note on `docs/cookbook/autocomplete.md`

That doc describes a per-collection `finders:` config block and a
`typesense.autocomplete_controller` service. **Neither exists in the current
code** — `Configuration.php` has no `finders:` node under `mappings`, and
there is no autocomplete controller anywhere in `src/`. Either it documents a
feature that was never finished, or one that existed before a refactor and
was never re-added. Build autocomplete today the same way as any other
search: a `Query` with `->prefix(true)` and a tight `->perPage()`, called
from your own controller.
