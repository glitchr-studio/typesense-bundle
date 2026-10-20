# Changelog

## 2.0 - the framework-free root (October 2026)

### Changed

- The package runs without a framework: everything but `Bridge/Symfony` and
  `TypesenseBundle` is plain PHP on Doctrine, the Typesense client and the
  standalone Cache and PropertyAccess components. `symfony/framework-bundle`
  and `doctrine/doctrine-bundle` move from `require` to `suggest` (with
  `symfony/console`); `beberlei/doctrineextensions` is no longer required.
- The Symfony wiring moved to `Bridge/Symfony` (`DependencyInjection\*`,
  `Command\*`, `Resources/config/services.php`). Their old names are aliases
  of the moved classes, declared on demand (`src/aliases.php`); service
  ids, the `typesense` configuration and the `typesense:*` commands are
  unchanged. `Resources/config/services.xml`, unused, is removed.
- `DBAL\Connection` takes the connection's parameters (an array) instead of
  the container's parameter bag; `TypesenseFinder` takes `bool $debug` and a
  `CacheInterface` (in memory by default); `TypesenseIndexer` takes an
  `$onError` callable (the bundle's puts a warning in the session's flash
  bag, as before) and the finders' cache. The former arguments - a
  parameter bag, the request stack - are still accepted.
- The hits are hydrated in their order by PHP instead of MySQL's `FIELD()`
  in DQL: any database. The bundle still registers `FIELD` where beberlei
  is installed.

### Added

- `#[Typesense\Bundle\Attribute\Collection]` and `#[Field]`: a class declares
  its collection itself (the same mapping as the configuration's, with its
  defaults); `typesense.attributes` names the directories read (`src/` by
  default). `ORM\Mapping\AttributeReader` reads them without a framework.
- A class named by a mapping is indexed without `TypesenseInterface`:
  `ORM\Transformer\PropertyReader` reads its fields (what `TypesenseTrait` did),
  related objects become strings by `__toString()`. The interface and the
  trait keep working (deprecated).
- A field's `options` (sort, optional, locale...), sent with the schema.

### Fixed

- Infix: a field's `infix: true` was dropped from the schema, and
  `Query::infix()` was never sent with more than one `query_by` field.
- `TypesenseFinder::facet()` asked for a facet field named "always" and
  always returned no facet.
- `Query::facetBy()` cut or padded its fields to the number of `query_by`
  fields: "tags,state" over `query_by` "state" asked for "tags" only (and
  three facets over two fields lost the third). The fields go as given.
- An entity without inheritance got a field named "" (no discriminator
  column); an entity without a second-level cache region failed to hydrate
  (`setCacheRegion(null)` on doctrine/orm 3); an identifier without a
  getter was read as empty: it is read through Doctrine's metadata.

## 2.0 — October 2026

### Changed (breaking for whoever relied on it)

- `typesense:create` drops and recreates the collections of the configured
  mappings only. It used to start by deleting **every** collection found on
  each connection, mapped or not, which emptied any other application or
  environment sharing the server. The whole-server sweep is gone: there is
  no option to bring it back (the short-lived `--all` is removed too).
  See the README, "Upgrading: `typesense:create` used to empty the server".

### Added

- `typesense.collection_prefix` (string, empty by default): put in front of
  every collection name sent to the server — create, delete, indexing (the
  Doctrine listener, `typesense:action`, `typesense:update`), finders and
  searches, each `multiSearch()` request, discriminator sub-collections.
  Mapping keys, finder service ids and `TypesenseMetadata::getName()` are
  unchanged; the server-side name is `TypesenseMetadata::getCollectionName()`.
  With the default empty prefix, collection names are what they were and
  nothing has to be reindexed.
- `typesense:list --collection` also accepts a mapping key.

### Fixed

- The bundle runs without glitchr/omnibase: `src/` called four of its global
  helpers (`str_strip()`, `is_cli()`, `usort_column()`, `first()`), undefined
  in any application that does not install it. They are plain PHP now.
  `psr/simple-cache` is required: `TypesenseFinder` and `TypesenseIndexer`
  take a `Psr\SimpleCache\CacheInterface` and build a `Psr16Cache`, and
  symfony/cache does not require it. The standalone suite is green.
- `docker-compose.test.yml`: the Typesense healthcheck called `curl`, which
  the `typesense/typesense` image does not have, so the test service never
  started. It now asks `/health` through bash's `/dev/tcp`.
