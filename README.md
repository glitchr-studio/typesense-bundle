# glitchr/typesense-bundle

Symfony integration for [Typesense](https://typesense.org/), structured like
Doctrine's own DBAL/ORM split:

- `DBAL/` — connections, low-level client wiring, multi-server support.
- `ORM/Mapping/` — collections, metadata (derived from real Doctrine
  `ClassMetadata`, including single-table-inheritance sub-collections).
- `ORM/Query/` — a fluent `Request`/`Query` builder and `Response` wrapper.
- `ORM/Transformer/` — entity ↔ Typesense document conversion.
- `EventListener/TypesenseIndexer` — keeps collections in sync with Doctrine
  entity lifecycle events automatically.

See [`docs/getting-started.md`](docs/getting-started.md) for a minimal,
working example, including a multi-server connection setup.

## Sharing a server: `collection_prefix`

A collection is named after its mapping key (`mappings.article` → the
`article` collection). Two environments of the same application pointed at
the same Typesense server — dev and test on one machine, a demo next to
them — would therefore read and write the very same collection.
`collection_prefix` puts a string in front of every collection name sent to
the server:

```yaml
# config/packages/typesense.yaml
typesense:
    mappings:
        article:
            class: 'App\Entity\Article'
            # ...

when@test:
    typesense:
        collection_prefix: 'test_'   # the "test_article" collection
```

- It is empty by default: without it, names are exactly what they were.
- It applies to everything the bundle sends to the server — creating and
  deleting a collection, indexing (the Doctrine listener,
  `typesense:action`, `typesense:update`), searching through a finder, the
  `collection` of each `multiSearch()` request — and to the
  sub-collections of a discriminator map (`test_article__blog`).
- It does **not** change what the application sees: the mapping key stays
  `article`, so do `typesense.finder.article`,
  `TypesenseManager::getFinder('article')` / `getCollection('article')` and
  `TypesenseMetadata::getName()`. The name on the server is
  `TypesenseMetadata::getCollectionName()`.
- `Connection::getCollection()` / `getDocuments()` / `getDocument()` are the
  raw client: they take a name as it is on the server, prefix included.
- Setting or changing it on an existing application points the mappings at
  collections that do not exist yet: run `typesense:create` and
  `typesense:action upsert` once. The collections under the old names are
  left where they are.

## `typesense:create` only touches its own collections

`bin/console typesense:create` drops and recreates the collections of the
configured mappings (under their `collection_prefix`), and nothing else.
Whatever else lives on the server — another application's collections,
another environment's, one created through the client directly — is left
alone.

```bash
bin/console typesense:create          # this application's collections only
bin/console typesense:create --all    # DESTRUCTIVE: the whole server first
```

`--all` first deletes **every** collection found on each configured
connection, mapped or not, then creates the mapped ones. Only use it on a
server that belongs to this application and this environment alone.

### Upgrading: `typesense:create` used to empty the server

Until this change `typesense:create` always behaved like `--all`. If
nothing but this application's mappings lives on the server, nothing
changes for you: the same collections are dropped and recreated. Two cases
do differ:

- You relied on it to wipe the server (a reset script, a CI job): add
  `--all`.
- A mapping was renamed or removed, or a discriminator value dropped: the
  collection it used to have is no longer swept away as a side effect, and
  stays on the server until it is deleted (`--all`, or by hand).

The other commands were already scoped to the mappings
(`typesense:action`, `typesense:update`) or read-only (`typesense:list`,
`typesense:health`). `typesense:list` shows every collection of the server
under its real name; its `--collection` filter takes that name or a mapping
key.

## Running the tests

Two suites:

- **`tests/Unit`** — no external dependencies, pure PHP + mocks. Runs
  anywhere PHP + this bundle's dependencies are installed.
- **`tests/Integration`** — exercises the real `typesense/typesense-php`
  client against a real Typesense server (skips itself if none is
  reachable).

### Locally, against the bundle's own `composer install`

```bash
composer install
composer test-unit          # fast, no server needed
composer test-integration    # needs TYPESENSE_URL / TYPESENSE_KEY pointing at a real server
composer test                # both suites
composer test-coverage       # HTML + text coverage report in var/coverage
```

### Full pipeline via Docker (spins up a real Typesense server too)

```bash
docker compose -f docker-compose.test.yml run --rm test
```

This builds `Dockerfile.test` (a bare `php:8.4-cli` image, `composer install`
as its own root package — no host Symfony app needed) and starts a real,
ephemeral (`tmpfs`) `typesense/typesense` server alongside it, wired via
`TYPESENSE_URL`/`TYPESENSE_KEY`. Use this exact setup as the base for a
`.gitlab-ci.yml`/GitHub Actions job.

### Inside a host application (dev workflow)

If this bundle is installed as a dependency (`vendor/glitchr/typesense-bundle`),
`tests/bootstrap.php` self-registers its test namespace against the host
app's own autoloader (which never picks up a dependency's `autoload-dev`):

```bash
docker exec -w /path/to/app/vendor/glitchr/typesense-bundle <web-container> \
    php ../../symfony/phpunit-bridge/bin/simple-phpunit
```
