# Changelog

All notable changes to the Glueful Meilisearch Extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
