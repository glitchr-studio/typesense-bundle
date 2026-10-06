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
                    property: title
                    type: string
                    infix: true       # supports prefix/infix search
                category:
                    property: category
                    type: string
                    facet: true       # filterable/facetable
                tags:
                    property: tags.slug   # nested property path
                    type: string[]
                publishedAt:
                    type: datetime
```

## 3. Make the entity indexable

```php
<?php
// src/Entity/Article.php
namespace App\Entity;

use Typesense\Bundle\TypesenseInterface;
use Typesense\Bundle\TypesenseTrait;

class Article implements TypesenseInterface
{
    use TypesenseTrait; // provides __typesenseGetter() — property-path reads,
                         // Collection -> array flattening, nested objects.

    // ... id, title, category, tags, publishedAt, as normal Doctrine mapping ...

    public function __typesense(): ?string
    {
        // Used when THIS entity appears as a value inside another entity's
        // Collection field (see TypesenseTrait::__typesenseGetter()).
        return $this->title;
    }
}
```

Nothing else to wire manually: `TypesenseIndexer` (a Doctrine
`postPersist`/`postUpdate`/`preRemove`/`postRemove`/`postFlush` listener) keeps
the Typesense collection in sync automatically whenever an `Article` is
persisted, updated, or removed through Doctrine.

## 4. Create the collection and backfill existing rows

```bash
# Drops and recreates the collection of every configured mapping — and only
# those: whatever else lives on the server is left alone. (`--all` first
# deletes EVERY collection on every connection, as the command always did
# before that option existed; destructive on a shared server.)
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
