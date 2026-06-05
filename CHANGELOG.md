# Changelog

All notable changes to the Glueful Meilisearch Extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.4.1] - 2026-06-05 — @queryParam Route Docs

### Changed
- **Route docblocks migrated to the editor-clean `@queryParam name:type="…"` tag** — parsed into the OpenAPI spec by the framework's `CommentsDocGenerator` as of 1.50.2 (the prior positional `@param … query …` form tripped IDE/Intelephense P1133 "undefined type" false positives). Redundant path-parameter docblocks were removed (path params auto-derive from the route URL). **Minimum framework raised to `glueful/framework >=1.50.2`** (`require-dev` `^1.50.2`). No runtime change.

## [1.4.0] - 2026-06-05 — Framework 1.50 Compatibility

### Changed
- **Minimum framework requirement raised to `glueful/framework >=1.50.1`** (`require-dev` pinned to `^1.50.1`); previously `>=1.28.0`.

### Build
- **Added `phpunit.xml`.** The extension shipped `tests/Unit` + `tests/Integration` directories but no PHPUnit config, so `composer test` printed PHPUnit's help instead of running. Now configured (Unit + Integration suites, `vendor/autoload.php` bootstrap). No tests are committed yet — the harness is in place for future ones.

### Notes
- Compatibility/maintenance release — **no code changes**. Verified clean against framework 1.50.1: no removed-API usage (no `Glueful\Repository\UserRepository`, no `Glueful\Events\EventListener`), `SearchController` is standalone (not `BaseController`), model→search sync fires via the ORM `static::created` lifecycle hook, and there are no migrations/FKs. (Seven pre-existing PHPStan style findings remain — dynamic-model method calls + strict comparisons — unrelated.)

## [1.3.1] - 2026-02-20

### Fixed

- **Typo tolerance setting key**: Changed `typo_tolerance` to `typoTolerance` to match Meilisearch API's camelCase naming convention. The snake_case key was silently ignored by Meilisearch, leaving typo tolerance at its default.

### Notes

- Patch release. No breaking changes.

---

## [1.3.0] - 2026-02-15

### Added
- **`ResolvesModelClass` trait** (`src/Console/ResolvesModelClass.php`): Resolves short model names (e.g., `Entity`) to fully qualified class names by trying `App\Models\` and `App\` prefixes before falling back to the original string. Allows `php glueful search:index --model=Entity` instead of requiring `--model=App\\Models\\Entity`.

### Fixed
- **`SearchCommand` argument registration crash**: `search:search` used `InputOption::VALUE_REQUIRED` (integer 4) and `InputOption::VALUE_OPTIONAL` for its `index` and `query` arguments instead of `InputArgument::REQUIRED` (1) and `InputArgument::OPTIONAL`. Since `InputOption::VALUE_REQUIRED` equals `InputArgument::IS_ARRAY` (4), Symfony expected an array default value and threw on command registration. Now uses the correct `InputArgument` constants.

### Changed
- **`SyncCommand` uses `ResolvesModelClass`**: Calls `$this->resolveModelClass()` before the `class_exists` check, enabling short model name resolution.
- **`IndexCommand` uses `ResolvesModelClass`**: Same short model name resolution as `SyncCommand`.

### Notes
- No breaking changes. CLI commands accept both short names and FQCNs.

## [1.2.3] - 2026-02-13

### Fixed
- **Circular Dependency**: Removed unused `MeilisearchEngine` parameter from `BatchIndexer` constructor. `BatchIndexer` only needs `IndexManager` — it delegates all Meilisearch operations through that. This eliminates the circular dependency: `MeilisearchEngine → BatchIndexer → MeilisearchEngine`.

### Notes
- Patch release. No breaking changes.

## [1.2.2] - 2026-02-09

### Fixed
- **Controller DI Registration**: `SearchController` was not registered in `MeilisearchProvider::services()`, causing `Service not found` errors when the router resolved the controller from the container. Controller is now explicitly registered with its dependencies.

### Notes
- Patch release. No breaking changes.

## [1.2.1] - 2026-02-06

### Changed
- **Version Management**: Version is now read from `composer.json` at runtime via `MeilisearchProvider::composerVersion()`.
  - `registerMeta()` in `boot()` now uses `self::composerVersion()` instead of a hardcoded string.
  - Future releases only require updating `composer.json` and `CHANGELOG.md`.

### Notes
- No breaking changes. Internal refactor only.

## [1.2.0] - 2026-02-05

### Added
- **CLI Commands**: New console commands for index management
  - `search:index` - Index searchable models with options:
    - `--model` - Model class to index
    - `--id` - Comma-separated IDs for selective indexing
    - `--fresh` - Flush index before indexing
    - Supports chunking for large datasets via `query()->chunk()`
  - `search:sync` - Sync index settings from models with options:
    - `--model` - Model class to sync settings for
    - `--dry-run` - Preview settings without applying
- **SyncSearchableJob**: Queue job for async search indexing
  - Handles `index` and `remove` actions
  - Works with ApplicationContext for proper request isolation
- **SearchController**: HTTP endpoints for search operations
  - `search()` - Query-param based search (`?index=&q=`)
  - `searchIndex()` - Path-param based search (`/{index}?q=`)
  - `status()` - List all Meilisearch indexes
  - Index validation with allowed indexes allowlist support
- **Index Allowlist**: Security feature to restrict searchable indexes
  - Configure via `MEILISEARCH_ALLOWED_INDEXES` env variable
  - Comma-separated index names (e.g., `posts,users,products`)

### Changed
- **MeilisearchEngine**: Wired `BatchIndexer` into `indexMany()` method
  - Bulk indexing now routes through the batch indexer for better performance
  - Handles chunked uploads to avoid memory issues with large datasets
- **MeilisearchProvider**: Enhanced service registration
  - Added `BatchIndexer` to DI container
  - Auto-discovery of CLI commands via `discoverCommands()`

### Documentation
- **README**: Added documentation for `MEILISEARCH_ALLOWED_INDEXES` environment variable
  - Explains index allowlist configuration for HTTP search routes
  - Includes env example with comma-separated index names

## [1.1.0] - 2026-02-05

### Changed
- **Framework Compatibility**: Updated minimum framework requirement to Glueful 1.28.0
  - Compatible with route caching infrastructure (Bellatrix release)
  - Routes already use `[Controller::class, 'method']` syntax - no code changes required
  - Controllers already accept `Request` directly with proper parameter extraction
- **SearchController**: Updated to use `Response::success()` for consistency with framework patterns
- **MeilisearchClient**: Added `@method` PHPDoc annotations for IDE support
  - Provides type hints for `index()` and `getIndexes()` methods
- **composer.json**: Updated `extra.glueful.requires.glueful` to `>=1.28.0`

### Notes
- This release ensures compatibility with Glueful Framework 1.28.0's route caching improvements
- Routes can now be compiled and cached for improved performance
- Run `composer update` after upgrading

## [1.0.0] - 2026-02-04

### Added

- **Searchable Trait**: Make any Glueful model searchable with the `Searchable` trait
  - Automatic syncing on model create, update, delete events
  - Transaction-safe indexing via framework's `afterCommit()` hooks
  - Configurable `shouldBeSearchable()` for conditional indexing
  - Customizable `toSearchableArray()` for document structure
  - Primary key strategy: consistent `id` field mapping for both `uuid` and `id` models

- **Search Query Builder**: Fluent API for building search queries
  - `where()`, `whereIn()`, `whereNotIn()` filter methods
  - `filter()` for raw Meilisearch filter expressions
  - `orderBy()` for sorting results
  - `limit()`, `offset()`, `skip()`, `take()` for pagination
  - `paginate()` with pagination metadata
  - `facets()` for faceted search
  - `highlight()` for search result highlighting
  - `select()` for attribute selection
  - `withMatchesPosition()` for match position data

- **Geo-Search Support**: Location-based search capabilities
  - `whereGeoRadius()` for radius-based filtering
  - `whereGeoBoundingBox()` for bounding box filtering
  - `orderByGeo()` for distance-based sorting
  - Follows Meilisearch `_geo` field convention

- **Search Result Wrapper**: Rich result handling
  - Model hydration with search result ordering preserved
  - `models()` for hydrated model instances
  - `all()` for raw hit data
  - `first()` for single result
  - `total()`, `count()`, `isEmpty()`, `isNotEmpty()` helpers
  - `facets()` and `facetStats()` for facet data
  - `paginationMeta()` for pagination information
  - `toArray()` for API-ready response format
  - `IteratorAggregate` implementation for foreach support

- **Index Manager**: Index lifecycle management
  - `createIndex()` with explicit `primaryKey='id'` setting
  - `getOrCreateIndex()` with primary key validation
  - `updateSettings()` for index configuration
  - `syncSettingsForModel()` to apply model settings
  - `deleteIndex()` and `flush()` for cleanup
  - `getStats()` and `getAllIndexes()` for monitoring
  - `waitForTask()` for synchronous operations

- **Meilisearch Client Wrapper**: Enhanced client with prefix support
  - Extends official `meilisearch/meilisearch-php` client
  - `prefixedIndexName()` for multi-tenant environments
  - `getPrefixedIndex()` convenience method

- **Batch Indexer**: Efficient bulk operations
  - Configurable batch size via `MEILISEARCH_BATCH_SIZE`
  - `indexMany()` for bulk document indexing
  - `removeMany()` for bulk document removal
  - Memory-efficient iteration support

- **CLI Commands**: Index management and debugging tools
  - `search:index` - Index models with `--model`, `--id`, `--fresh` options
  - `search:flush` - Flush indexes with `--all`, `--force` options
  - `search:status` - Show index stats with `--json` option
  - `search:sync` - Sync model settings with `--dry-run` option
  - `search:search` - Debug search with `--filter`, `--limit`, `--raw` options
  - All commands use `#[AsCommand]` attribute for auto-discovery

- **Queue Support**: Async indexing for production
  - Optional queue-based indexing via `MEILISEARCH_QUEUE=true`
  - Configurable queue connection and name
  - `SyncSearchableJob` for queue workers
  - Queue dispatch wrapped in `afterCommit()` for transaction safety

- **API Routes**: REST endpoints for search
  - `GET /api/search` - Universal search with index query param
  - `GET /api/search/{index}` - Search specific index
  - `GET /api/search/admin/status` - Admin-only index status
  - Full OpenAPI-style route documentation

- **Service Provider**: Framework integration
  - DI container service registration
  - Configuration merging from `config/meilisearch.php`
  - Extension metadata registration
  - Command auto-discovery via `discoverCommands()`
  - Route loading

- **Additional Components**:
  - `SearchEngineInterface` and `SearchableInterface` contracts
  - `MeilisearchEngine` implementation
  - `NullEngine` for testing/disabled search
  - `FilterBuilder` helper for AND/OR/NOT expressions
  - `DocumentBuilder` for consistent document creation
  - Event classes: `ModelIndexed`, `ModelRemoved`, `SearchPerformed`
  - Listeners: `SyncModelToSearch`, `QueuedSyncListener`
  - `SearchController` for API endpoints

### Dependencies

- Requires PHP 8.3+
- Requires Glueful Framework 1.27.0+
- Requires `meilisearch/meilisearch-php` ^1.6

[Unreleased]: https://github.com/glueful/meilisearch/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/glueful/meilisearch/releases/tag/v1.0.0
