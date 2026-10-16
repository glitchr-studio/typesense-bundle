# Changelog

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

- `docker-compose.test.yml`: the Typesense healthcheck called `curl`, which
  the `typesense/typesense` image does not have, so the test service never
  started. It now asks `/health` through bash's `/dev/tcp`.
