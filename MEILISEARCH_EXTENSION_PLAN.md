# Meilisearch Extension Implementation Plan

> Implementation plan for `glueful/meilisearch` - A full-text search adapter for Glueful Framework

## Overview

The Meilisearch extension provides seamless integration between Glueful Framework and [Meilisearch](https://www.meilisearch.com/), an open-source, lightning-fast search engine. This extension enables models to be searchable with minimal configuration while providing advanced search features like typo tolerance, filtering, faceting, and geo-search.

### Goals

1. **Simple Integration** - Make any model searchable with a trait and minimal configuration
2. **Automatic Syncing** - Keep search index in sync with database changes via events
3. **Powerful Querying** - Full access to Meilisearch features (filters, facets, sorting, geo)
4. **Developer Experience** - CLI commands for index management and debugging
5. **Performance** - Batch operations, queue support, lazy indexing

### Non-Goals

- This extension focuses on Meilisearch only (not Elasticsearch, Algolia, etc.)
- Real-time search suggestions (can be built on top)
- Search analytics dashboard (future extension)

---

## Architectural Decisions

### Primary Key Strategy

**Decision:** Meilisearch documents always use `id` as the primary key field name. The value comes from the model's configured key (`uuid` or `id`).

**Rationale:**
- Meilisearch requires a consistent primary key field name per index (default: `id`)
- Glueful models may use either `uuid` (12-char string) or `id` (auto-increment integer)
- Using `id` as the Meilisearch field name with the model's actual key value provides consistency

**Critical Requirement:** When creating indexes via `IndexManager::createIndex()`, we **explicitly set `primaryKey = 'id'`** to guarantee this assumption. This is not left to Meilisearch's auto-detection.

```php
// In IndexManager::createIndex() - always set primaryKey explicitly
$task = $this->client->createIndex($indexName, ['primaryKey' => 'id']);
```

**Index Primary Key Validation:**

When managing existing indexes, `IndexManager` validates that the primary key matches our expected `id` field:

```php
// In IndexManager - validate existing index primaryKey
public function validateIndex(string $name): void
{
    $index = $this->client->getIndex($this->prefixedName($name));
    $primaryKey = $index->getPrimaryKey();

    if ($primaryKey !== null && $primaryKey !== 'id') {
        throw new IndexConfigurationException(
            "Index '{$name}' has primaryKey '{$primaryKey}', expected 'id'. " .
            "This extension requires all indexes to use 'id' as the primary key. " .
            "Either recreate the index or manually update it."
        );
    }
}

// Called during sync operations to catch mismatches early
public function getOrCreateIndex(string $name): Indexes
{
    try {
        $index = $this->client->getIndex($this->prefixedName($name));

        // Validate primaryKey matches expected 'id'
        $pk = $index->getPrimaryKey();
        if ($pk !== null && $pk !== 'id') {
            $this->logger->warning("Index '{$name}' has unexpected primaryKey '{$pk}'");
        }

        return $index;
    } catch (ApiException $e) {
        if ($e->httpStatus === 404) {
            $this->createIndex($name); // Always creates with primaryKey='id'
            return $this->client->getIndex($this->prefixedName($name));
        }
        throw $e;
    }
}
```

**Implementation:**
```php
// In Searchable trait
public function getSearchableKeyName(): string
{
    // Returns 'uuid' if model has uuid, otherwise 'id'
    return property_exists($this, 'uuid') || isset($this->uuid) ? 'uuid' : 'id';
}

// In toSearchableArray() - always output as 'id' for Meilisearch
public function toSearchableArray(): array
{
    $data = $this->toArray();
    $data['id'] = $this->getSearchableId(); // Maps uuid/id -> 'id'
    return $data;
}

// In SearchResult::models() - map back to model's key with fallback
// Guard against models that don't implement SearchableInterface
$searchableKeyField = method_exists($this->model, 'getSearchableKeyName')
    ? $this->model->getSearchableKeyName()
    : 'id'; // Fallback to 'id' if method doesn't exist
$ids = array_column($this->hits, 'id'); // Always 'id' from Meilisearch
$models = Model::whereIn($searchableKeyField, $ids)->get(); // Query by uuid/id
```

### Transaction-Safe Indexing

**Decision:** Indexing operations are deferred until after database transactions commit.

**Rationale:**
- Model events (`created`, `updated`, `deleted`) fire before transaction commit
- Indexing uncommitted data can cause search results to include rolled-back records

**Framework Support (v1.23+):**

Glueful Framework provides native after-commit callback support via `TransactionManager` and the `db()` helper:

```php
// Framework provides these methods on Connection:
db($context)->afterCommit(callable $callback);   // Execute after commit
db($context)->afterRollback(callable $callback); // Execute after rollback
db($context)->withinTransaction();               // Check if in transaction
db($context)->transactionLevel();                // Get nesting level
db($context)->transaction(callable $callback);   // Execute in transaction
```

**Implementation in Searchable trait:**

```php
// Usage in Searchable trait - uses native framework support
protected function queueSearchableSync(): void
{
    $callback = fn () => $this->searchableSync();

    // Use framework's native afterCommit support
    // If in transaction: deferred until commit
    // If not in transaction: executed immediately
    db($this->getApplicationContext())->afterCommit($callback);
}

protected function queueSearchableRemove(): void
{
    $callback = fn () => $this->searchableRemove();
    db($this->getApplicationContext())->afterCommit($callback);
}
```

**Queue-Based Approach (Recommended for Production):**

For maximum reliability, enable queue-based indexing which decouples indexing from the HTTP request.

**Important:** Queue dispatch MUST also be wrapped in `afterCommit()` to prevent enqueueing jobs for data that might be rolled back. If a job is dispatched immediately inside a transaction and the transaction rolls back, the queue worker would try to index non-existent data.

```php
// When MEILISEARCH_QUEUE=true, indexing is dispatched to queue AFTER commit
protected function queueSearchableSync(): void
{
    $context = $this->getApplicationContext();

    if (config('meilisearch.queue.enabled')) {
        // CRITICAL: Wrap dispatch in afterCommit to prevent race conditions
        // This ensures jobs are only enqueued after the transaction commits
        db($context)->afterCommit(function () {
            dispatch(new SyncSearchableJob($this))
                ->onQueue(config('meilisearch.queue.queue', 'search'));
        });
    } else {
        // Use framework's afterCommit for synchronous indexing
        db($context)->afterCommit(fn () => $this->searchableSync());
    }
}
```

**Why wrap queue dispatch in afterCommit?**
- If dispatch happens immediately inside a transaction and the transaction rolls back, the job would execute against non-existent data
- The queue worker might process the job before the transaction commits (race condition)
- Wrapping in `afterCommit()` guarantees: no commit = no job enqueued

**Fallback Hierarchy:**
1. **Queue enabled + transaction:** Job dispatch deferred via `afterCommit()`, then processed by worker
2. **Queue enabled + no transaction:** Job dispatched immediately to queue
3. **Queue disabled + transaction:** Sync indexing deferred via `afterCommit()`
4. **Queue disabled + no transaction:** Sync indexing executed immediately

**Recommended:** Enable queue-based indexing (`MEILISEARCH_QUEUE=true`) for production to ensure data consistency.

### Geo-Search Field Convention

**Decision:** Geo-location data must be stored in a `_geo` field with `lat` and `lng` properties.

**Rationale:**
- Meilisearch's geo functions (`_geoRadius`, `_geoBoundingBox`, `_geoPoint`) operate on the reserved `_geo` field
- This is a Meilisearch requirement, not configurable per-attribute

**Implementation:**
```php
// In model's toSearchableArray()
public function toSearchableArray(): array
{
    return [
        'id' => $this->uuid,
        'name' => $this->name,
        '_geo' => [
            'lat' => $this->latitude,
            'lng' => $this->longitude,
        ],
    ];
}

// In model's searchable settings
public function getSearchableFilterableAttributes(): array
{
    return ['_geo', 'category', 'status'];
}

public function getSearchableSortableAttributes(): array
{
    return ['_geo', 'created_at'];
}
```

---

## Package Information

```json
{
  "name": "glueful/meilisearch",
  "description": "Meilisearch full-text search integration for Glueful Framework",
  "type": "glueful-extension",
  "license": "MIT",
  "require": {
    "php": "^8.3",
    "glueful/framework": "^1.22",
    "meilisearch/meilisearch-php": "^1.6"
  },
  "autoload": {
    "psr-4": {
      "Glueful\\Extensions\\Meilisearch\\": "src/"
    }
  },
  "extra": {
    "glueful": {
      "name": "Meilisearch",
      "displayName": "Meilisearch Search",
      "description": "Full-text search powered by Meilisearch",
      "version": "1.0.0",
      "icon": "assets/icon.png",
      "categories": ["search", "database"],
      "publisher": "glueful-team",
      "provider": "Glueful\\Extensions\\Meilisearch\\MeilisearchProvider",
      "requires": {
        "glueful": ">=1.22.0",
        "extensions": []
      }
    }
  }
}
```

---

## Directory Structure

```
meilisearch/
├── .gitignore
├── composer.json
├── CHANGELOG.md
├── README.md
├── assets/
│   └── icon.png
├── config/
│   └── meilisearch.php
├── src/
│   ├── MeilisearchProvider.php       # Main service provider
│   ├── Client/
│   │   ├── MeilisearchClient.php     # Wrapped Meilisearch client
│   │   └── ClientFactory.php         # Client instantiation
│   ├── Contracts/
│   │   ├── SearchableInterface.php   # Interface for searchable models
│   │   ├── SearchEngineInterface.php # Search engine contract
│   │   └── IndexConfigInterface.php  # Index configuration contract
│   ├── Engine/
│   │   ├── MeilisearchEngine.php     # Search engine implementation
│   │   └── NullEngine.php            # Testing/disabled search
│   ├── Indexing/
│   │   ├── DocumentBuilder.php       # Build documents for indexing
│   │   ├── BatchIndexer.php          # Batch indexing operations
│   │   └── IndexManager.php          # Index lifecycle management
│   ├── Query/
│   │   ├── SearchQuery.php           # Fluent search query builder
│   │   ├── SearchResult.php          # Search result wrapper
│   │   └── FilterBuilder.php         # Filter expression builder
│   ├── Model/
│   │   └── Searchable.php            # Trait for searchable models
│   ├── Events/
│   │   ├── ModelIndexed.php          # Event after indexing
│   │   ├── ModelRemoved.php          # Event after removal
│   │   └── SearchPerformed.php       # Event after search
│   ├── Listeners/
│   │   ├── SyncModelToSearch.php     # Listener for model events
│   │   └── QueuedSyncListener.php    # Queued version
│   ├── Console/
│   │   ├── IndexCommand.php          # Index all/specific models
│   │   ├── FlushCommand.php          # Clear index
│   │   ├── StatusCommand.php         # Show index stats
│   │   ├── SyncCommand.php           # Sync index settings
│   │   └── SearchCommand.php         # CLI search for debugging
│   └── routes.php                     # Optional API routes
└── tests/
    ├── Unit/
    │   ├── SearchQueryTest.php
    │   ├── DocumentBuilderTest.php
    │   └── FilterBuilderTest.php
    └── Integration/
        ├── IndexingTest.php
        └── SearchTest.php
```

---

## Configuration

### config/meilisearch.php

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meilisearch Connection
    |--------------------------------------------------------------------------
    |
    | Configure your Meilisearch server connection. The host should include
    | the protocol (http/https) and port if not using default 7700.
    |
    */
    'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
    'key' => env('MEILISEARCH_KEY', null),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix all index names to avoid collisions in shared Meilisearch
    | instances. Useful for multi-tenant or staging/production separation.
    |
    */
    'prefix' => env('MEILISEARCH_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Enable queued indexing for better performance. When enabled, model
    | changes are dispatched to the queue instead of syncing immediately.
    |
    */
    'queue' => [
        'enabled' => (bool) env('MEILISEARCH_QUEUE', false),
        'connection' => env('MEILISEARCH_QUEUE_CONNECTION', null),
        'queue' => env('MEILISEARCH_QUEUE_NAME', 'search'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Configuration
    |--------------------------------------------------------------------------
    |
    | Configure batch operations for bulk indexing. Larger batches are more
    | efficient but use more memory.
    |
    */
    'batch' => [
        'size' => (int) env('MEILISEARCH_BATCH_SIZE', 500),
        'timeout' => (int) env('MEILISEARCH_BATCH_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    |
    | When enabled, soft-deleted models will be removed from the search index.
    | Set to false to keep soft-deleted records searchable.
    |
    */
    'soft_delete' => (bool) env('MEILISEARCH_SOFT_DELETE', true),

    /*
    |--------------------------------------------------------------------------
    | Search Defaults
    |--------------------------------------------------------------------------
    |
    | Default search behavior. These can be overridden per-query.
    |
    */
    'search' => [
        'limit' => (int) env('MEILISEARCH_DEFAULT_LIMIT', 20),
        'attributes_to_highlight' => ['*'],
        'highlight_pre_tag' => '<em>',
        'highlight_post_tag' => '</em>',
    ],

    /*
    |--------------------------------------------------------------------------
    | Index Settings
    |--------------------------------------------------------------------------
    |
    | Default index settings applied when creating new indexes. Individual
    | models can override these via their searchableSettings() method.
    |
    */
    'index_settings' => [
        'pagination' => [
            'maxTotalHits' => 10000,
        ],
        'typo_tolerance' => [
            'enabled' => true,
            'minWordSizeForTypos' => [
                'oneTypo' => 5,
                'twoTypos' => 9,
            ],
        ],
    ],
];
```

---

## Core Components

### 1. Searchable Trait

The main integration point for models.

```php
<?php

namespace Glueful\Extensions\Meilisearch\Model;

use Glueful\Extensions\Meilisearch\Query\SearchQuery;

trait Searchable
{
    /**
     * Boot the searchable trait.
     * Registers model event listeners for automatic syncing.
     *
     * Uses after-commit callbacks to avoid indexing uncommitted data.
     * If queue is enabled, indexing is dispatched to the queue.
     */
    public static function bootSearchable(): void
    {
        // Use after-commit hooks to avoid indexing rolled-back data
        static::created(fn ($model) => $model->queueSearchableSync());
        static::updated(fn ($model) => $model->queueSearchableSync());
        static::deleted(fn ($model) => $model->queueSearchableRemove());

        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($model) => $model->queueSearchableSync());
        }
    }

    /**
     * Queue the searchable sync operation.
     * Dispatches after transaction commits if in a transaction.
     */
    protected function queueSearchableSync(): void
    {
        $callback = fn () => $this->searchableSync();

        // If using transactions, defer until after commit
        if ($this->withinTransaction()) {
            $this->afterCommit($callback);
        } else {
            $callback();
        }
    }

    /**
     * Queue the searchable remove operation.
     * Dispatches after transaction commits if in a transaction.
     */
    protected function queueSearchableRemove(): void
    {
        $callback = fn () => $this->searchableRemove();

        if ($this->withinTransaction()) {
            $this->afterCommit($callback);
        } else {
            $callback();
        }
    }

    /**
     * Get the index name for this model.
     */
    public function searchableAs(): string
    {
        return $this->getTable();
    }

    /**
     * Get the document ID for search index.
     * Meilisearch requires 'id' as primary key field name.
     */
    public function getSearchableId(): string|int
    {
        return $this->{$this->getSearchableKeyName()};
    }

    /**
     * Get the model's key name used for search indexing.
     * Returns 'uuid' if model has uuid property, otherwise 'id'.
     */
    public function getSearchableKeyName(): string
    {
        return property_exists($this, 'uuid') || isset($this->uuid) ? 'uuid' : 'id';
    }

    /**
     * Get the data to be indexed.
     * Override this method to customize indexed fields.
     * Note: The returned array MUST include 'id' key for Meilisearch.
     */
    public function toSearchableArray(): array
    {
        $data = $this->toArray();

        // Ensure 'id' field exists for Meilisearch primary key
        if (!isset($data['id'])) {
            $data['id'] = $this->getSearchableId();
        }

        return $data;
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return true;
    }

    /**
     * Get searchable index settings.
     * Override to customize Meilisearch index settings.
     */
    public function searchableSettings(): array
    {
        return [];
    }

    /**
     * Get searchable attributes for filtering.
     */
    public function getSearchableFilterableAttributes(): array
    {
        return [];
    }

    /**
     * Get searchable attributes for sorting.
     */
    public function getSearchableSortableAttributes(): array
    {
        return [];
    }

    /**
     * Sync this model to search index.
     */
    public function searchableSync(): void
    {
        if (!$this->shouldBeSearchable()) {
            $this->searchableRemove();
            return;
        }

        app(SearchEngineInterface::class)->index($this);
    }

    /**
     * Remove this model from search index.
     */
    public function searchableRemove(): void
    {
        app(SearchEngineInterface::class)->remove($this);
    }

    /**
     * Search this model's index.
     */
    public static function search(string $query = ''): SearchQuery
    {
        return new SearchQuery(new static(), $query);
    }
}
```

### 2. Searchable Interface

All models used with SearchQuery and SearchResult **MUST** implement this interface or use the `Searchable` trait (which implements it).

```php
<?php

namespace Glueful\Extensions\Meilisearch\Contracts;

/**
 * Interface for models that can be indexed in Meilisearch.
 *
 * Models must implement this interface to be compatible with SearchQuery,
 * SearchResult, and the search engine. The Searchable trait provides a
 * default implementation.
 */
interface SearchableInterface
{
    /**
     * Get the index name for this model.
     */
    public function searchableAs(): string;

    /**
     * Get the document ID for search index.
     * This value is stored as 'id' in Meilisearch.
     */
    public function getSearchableId(): string|int;

    /**
     * Get the model's key name used for search indexing.
     * Returns 'uuid' if model uses UUID, otherwise 'id'.
     */
    public function getSearchableKeyName(): string;

    /**
     * Get the data to be indexed.
     * The returned array MUST include an 'id' key for Meilisearch.
     */
    public function toSearchableArray(): array;

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool;

    /**
     * Sync this model to search index.
     */
    public function searchableSync(): void;

    /**
     * Remove this model from search index.
     */
    public function searchableRemove(): void;
}
```

### 3. Search Engine Interface

```php
<?php

namespace Glueful\Extensions\Meilisearch\Contracts;

interface SearchEngineInterface
{
    /**
     * Index a single model.
     */
    public function index(SearchableInterface $model): void;

    /**
     * Index multiple models.
     *
     * @param iterable<SearchableInterface> $models
     */
    public function indexMany(iterable $models): void;

    /**
     * Remove a model from the index.
     */
    public function remove(SearchableInterface $model): void;

    /**
     * Remove multiple models from the index.
     *
     * @param iterable<SearchableInterface> $models
     */
    public function removeMany(iterable $models): void;

    /**
     * Flush all documents from an index.
     */
    public function flush(string $index): void;

    /**
     * Perform a search query.
     *
     * @return array{hits: array, estimatedTotalHits: int, processingTimeMs: int}
     */
    public function search(SearchQuery $query): array;

    /**
     * Update index settings.
     */
    public function updateSettings(string $index, array $settings): void;

    /**
     * Get index statistics.
     */
    public function getIndexStats(string $index): array;
}
```

### 4. Search Query Builder

```php
<?php

namespace Glueful\Extensions\Meilisearch\Query;

use Glueful\Extensions\Meilisearch\Contracts\SearchableInterface;

class SearchQuery
{
    private string $query;
    private object $model;
    private ?int $limit = null;
    private ?int $offset = null;
    private array $filters = [];
    private array $facets = [];
    private array $sort = [];
    private array $attributesToRetrieve = ['*'];
    private array $attributesToHighlight = [];
    private bool $showMatchesPosition = false;

    public function __construct(object $model, string $query = '')
    {
        $this->model = $model;
        $this->query = $query;
    }

    /**
     * Set the search query string.
     */
    public function query(string $query): static
    {
        $this->query = $query;
        return $this;
    }

    /**
     * Filter results using Meilisearch filter syntax.
     *
     * @param string|array $filter Filter expression or array of expressions
     */
    public function filter(string|array $filter): static
    {
        if (is_array($filter)) {
            $this->filters = array_merge($this->filters, $filter);
        } else {
            $this->filters[] = $filter;
        }
        return $this;
    }

    /**
     * Add a where clause (converted to Meilisearch filter).
     */
    public function where(string $attribute, mixed $operator, mixed $value = null): static
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->filters[] = match ($operator) {
            '=' => "{$attribute} = " . $this->formatValue($value),
            '!=' => "{$attribute} != " . $this->formatValue($value),
            '>' => "{$attribute} > " . $this->formatValue($value),
            '>=' => "{$attribute} >= " . $this->formatValue($value),
            '<' => "{$attribute} < " . $this->formatValue($value),
            '<=' => "{$attribute} <= " . $this->formatValue($value),
            'IN' => "{$attribute} IN [" . implode(', ', array_map([$this, 'formatValue'], (array) $value)) . "]",
            'NOT IN' => "{$attribute} NOT IN [" . implode(', ', array_map([$this, 'formatValue'], (array) $value)) . "]",
            'EXISTS' => "{$attribute} EXISTS",
            'NOT EXISTS' => "{$attribute} NOT EXISTS",
            'IS NULL' => "{$attribute} IS NULL",
            'IS NOT NULL' => "{$attribute} IS NOT NULL",
            'IS EMPTY' => "{$attribute} IS EMPTY",
            'IS NOT EMPTY' => "{$attribute} IS NOT EMPTY",
            default => throw new \InvalidArgumentException("Unsupported operator: {$operator}"),
        };

        return $this;
    }

    /**
     * Add a where-in clause.
     */
    public function whereIn(string $attribute, array $values): static
    {
        return $this->where($attribute, 'IN', $values);
    }

    /**
     * Add a where-not-in clause.
     */
    public function whereNotIn(string $attribute, array $values): static
    {
        return $this->where($attribute, 'NOT IN', $values);
    }

    /**
     * Add a geo-radius filter.
     *
     * Filters documents within a radius from a point.
     * Requires '_geo' field to be set in index settings as a filterable attribute.
     *
     * @param float $lat Latitude of center point
     * @param float $lng Longitude of center point
     * @param int $radiusMeters Radius in meters
     */
    public function whereGeoRadius(float $lat, float $lng, int $radiusMeters): static
    {
        $this->filters[] = "_geoRadius({$lat}, {$lng}, {$radiusMeters})";
        return $this;
    }

    /**
     * Add a geo-bounding-box filter.
     *
     * Filters documents within a bounding box.
     * Requires '_geo' field to be set in index settings as a filterable attribute.
     *
     * @param array $topLeft [lat, lng] of top-left corner
     * @param array $bottomRight [lat, lng] of bottom-right corner
     */
    public function whereGeoBoundingBox(array $topLeft, array $bottomRight): static
    {
        $this->filters[] = "_geoBoundingBox([{$topLeft[0]}, {$topLeft[1]}], [{$bottomRight[0]}, {$bottomRight[1]}])";
        return $this;
    }

    /**
     * Request facet distribution for attributes.
     */
    public function facets(array $attributes): static
    {
        $this->facets = $attributes;
        return $this;
    }

    /**
     * Sort results.
     */
    public function orderBy(string $attribute, string $direction = 'asc'): static
    {
        $this->sort[] = $attribute . ':' . strtolower($direction);
        return $this;
    }

    /**
     * Sort by geo-distance from a point.
     *
     * Requires '_geo' field to be set in index settings as a sortable attribute.
     *
     * @param float $lat Latitude of reference point
     * @param float $lng Longitude of reference point
     * @param string $direction 'asc' for nearest first, 'desc' for farthest first
     */
    public function orderByGeo(float $lat, float $lng, string $direction = 'asc'): static
    {
        $this->sort[] = "_geoPoint({$lat}, {$lng}):" . strtolower($direction);
        return $this;
    }

    /**
     * Limit results.
     */
    public function limit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Alias for limit.
     */
    public function take(int $limit): static
    {
        return $this->limit($limit);
    }

    /**
     * Offset results.
     */
    public function offset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Alias for offset.
     */
    public function skip(int $offset): static
    {
        return $this->offset($offset);
    }

    /**
     * Paginate results.
     */
    public function paginate(int $page = 1, int $perPage = 20): SearchResult
    {
        $this->limit = $perPage;
        $this->offset = ($page - 1) * $perPage;

        $result = $this->get();

        return $result->withPagination($page, $perPage);
    }

    /**
     * Select specific attributes to retrieve.
     */
    public function select(array $attributes): static
    {
        $this->attributesToRetrieve = $attributes;
        return $this;
    }

    /**
     * Highlight specific attributes.
     */
    public function highlight(array $attributes = ['*']): static
    {
        $this->attributesToHighlight = $attributes;
        return $this;
    }

    /**
     * Include match positions in results.
     */
    public function withMatchesPosition(): static
    {
        $this->showMatchesPosition = true;
        return $this;
    }

    /**
     * Execute the search and return results.
     */
    public function get(): SearchResult
    {
        $engine = app(SearchEngineInterface::class);
        $rawResult = $engine->search($this);

        return new SearchResult($rawResult, $this->model);
    }

    /**
     * Get raw search results without model hydration.
     */
    public function raw(): array
    {
        return app(SearchEngineInterface::class)->search($this);
    }

    /**
     * Get the first result.
     */
    public function first(): ?object
    {
        return $this->limit(1)->get()->first();
    }

    /**
     * Get the model being searched.
     */
    public function getModel(): object
    {
        return $this->model;
    }

    /**
     * Get the search query string.
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Build the search parameters array for Meilisearch.
     */
    public function toSearchParams(): array
    {
        $params = [];

        if ($this->limit !== null) {
            $params['limit'] = $this->limit;
        }

        if ($this->offset !== null) {
            $params['offset'] = $this->offset;
        }

        if ($this->filters !== []) {
            $params['filter'] = $this->filters;
        }

        if ($this->facets !== []) {
            $params['facets'] = $this->facets;
        }

        if ($this->sort !== []) {
            $params['sort'] = $this->sort;
        }

        if ($this->attributesToRetrieve !== ['*']) {
            $params['attributesToRetrieve'] = $this->attributesToRetrieve;
        }

        if ($this->attributesToHighlight !== []) {
            $params['attributesToHighlight'] = $this->attributesToHighlight;
        }

        if ($this->showMatchesPosition) {
            $params['showMatchesPosition'] = true;
        }

        return $params;
    }

    private function formatValue(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . addslashes($value) . '"';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }
}
```

### 5. Search Result

```php
<?php

namespace Glueful\Extensions\Meilisearch\Query;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

class SearchResult implements IteratorAggregate
{
    private array $hits;
    private int $estimatedTotalHits;
    private int $processingTimeMs;
    private array $facetDistribution;
    private array $facetStats;
    private object $model;
    private ?int $page = null;
    private ?int $perPage = null;

    public function __construct(array $rawResult, object $model)
    {
        $this->hits = $rawResult['hits'] ?? [];
        $this->estimatedTotalHits = $rawResult['estimatedTotalHits'] ?? count($this->hits);
        $this->processingTimeMs = $rawResult['processingTimeMs'] ?? 0;
        $this->facetDistribution = $rawResult['facetDistribution'] ?? [];
        $this->facetStats = $rawResult['facetStats'] ?? [];
        $this->model = $model;
    }

    /**
     * Get all hits as an array.
     */
    public function all(): array
    {
        return $this->hits;
    }

    /**
     * Get hits hydrated as model instances.
     *
     * Note: The model MUST implement SearchableInterface or use the Searchable trait.
     * Models without getSearchableKeyName() will fall back to 'id' as the key field.
     */
    public function models(): array
    {
        if ($this->hits === []) {
            return [];
        }

        // Get the searchable key field name from the model
        // The Searchable trait enforces 'id' as the document primary key in Meilisearch
        // Fall back to 'id' if model doesn't implement getSearchableKeyName()
        $searchableKeyField = method_exists($this->model, 'getSearchableKeyName')
            ? $this->model->getSearchableKeyName()
            : 'id';

        // Extract IDs from hits using the consistent 'id' field
        // (Meilisearch always returns 'id' as primary key - we explicitly set primaryKey='id' during index creation)
        $ids = array_column($this->hits, 'id');

        if ($ids === []) {
            return [];
        }

        // Fetch models from database using the model's actual key
        $modelClass = get_class($this->model);
        $models = $modelClass::whereIn($searchableKeyField, $ids)->get();

        // Index by the searchable key for ordering
        $indexed = [];
        foreach ($models as $model) {
            $indexed[$model->{$searchableKeyField}] = $model;
        }

        // Return in search result order
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($indexed[$id])) {
                $ordered[] = $indexed[$id];
            }
        }

        return $ordered;
    }

    /**
     * Get the first hit.
     */
    public function first(): ?object
    {
        $models = $this->models();
        return $models[0] ?? null;
    }

    /**
     * Get the estimated total number of hits.
     */
    public function total(): int
    {
        return $this->estimatedTotalHits;
    }

    /**
     * Get the processing time in milliseconds.
     */
    public function processingTime(): int
    {
        return $this->processingTimeMs;
    }

    /**
     * Get facet distribution.
     */
    public function facets(?string $attribute = null): array
    {
        if ($attribute !== null) {
            return $this->facetDistribution[$attribute] ?? [];
        }
        return $this->facetDistribution;
    }

    /**
     * Get facet stats (for numeric facets).
     */
    public function facetStats(?string $attribute = null): array
    {
        if ($attribute !== null) {
            return $this->facetStats[$attribute] ?? [];
        }
        return $this->facetStats;
    }

    /**
     * Check if there are any results.
     */
    public function isEmpty(): bool
    {
        return $this->hits === [];
    }

    /**
     * Check if there are results.
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Get the number of hits returned.
     */
    public function count(): int
    {
        return count($this->hits);
    }

    /**
     * Set pagination info for result.
     */
    public function withPagination(int $page, int $perPage): static
    {
        $this->page = $page;
        $this->perPage = $perPage;
        return $this;
    }

    /**
     * Get pagination metadata.
     */
    public function paginationMeta(): array
    {
        if ($this->page === null || $this->perPage === null) {
            return [];
        }

        $totalPages = (int) ceil($this->estimatedTotalHits / $this->perPage);

        return [
            'current_page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->estimatedTotalHits,
            'total_pages' => $totalPages,
            'has_more' => $this->page < $totalPages,
        ];
    }

    /**
     * Convert to array (for API responses).
     */
    public function toArray(): array
    {
        return [
            'data' => $this->hits,
            'meta' => [
                'total' => $this->estimatedTotalHits,
                'processing_time_ms' => $this->processingTimeMs,
                ...$this->paginationMeta(),
            ],
            'facets' => $this->facetDistribution !== [] ? $this->facetDistribution : null,
        ];
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->models());
    }
}
```

### 6. Index Manager

The IndexManager handles index lifecycle operations including creation with explicit primary key configuration.

```php
<?php

namespace Glueful\Extensions\Meilisearch\Indexing;

use Glueful\Extensions\Meilisearch\Client\MeilisearchClient;
use Meilisearch\Endpoints\Indexes;

class IndexManager
{
    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly string $prefix = ''
    ) {}

    /**
     * Create a new index with explicit primary key.
     *
     * IMPORTANT: We ALWAYS use 'id' as the Meilisearch primary key field name.
     * The model's actual key (uuid or id) is mapped to this field in toSearchableArray().
     * This ensures consistent behavior across all searchable models.
     *
     * @param string $name Index name (without prefix)
     * @param string $primaryKey Always 'id' for consistency (default)
     */
    public function createIndex(string $name, string $primaryKey = 'id'): array
    {
        $indexName = $this->prefixedName($name);

        // Explicitly set primaryKey to 'id' to guarantee consistent document lookups
        // This is critical for SearchResult::models() to work correctly
        $task = $this->client->createIndex($indexName, ['primaryKey' => $primaryKey]);

        return $this->waitForTask($task['taskUid']);
    }

    /**
     * Get or create an index.
     */
    public function getOrCreateIndex(string $name): Indexes
    {
        $indexName = $this->prefixedName($name);

        try {
            return $this->client->getIndex($indexName);
        } catch (\Meilisearch\Exceptions\ApiException $e) {
            if ($e->httpStatus === 404) {
                $this->createIndex($name);
                return $this->client->getIndex($indexName);
            }
            throw $e;
        }
    }

    /**
     * Update index settings.
     */
    public function updateSettings(string $name, array $settings): array
    {
        $index = $this->getOrCreateIndex($name);
        $task = $index->updateSettings($settings);

        return $this->waitForTask($task['taskUid']);
    }

    /**
     * Delete an index.
     */
    public function deleteIndex(string $name): array
    {
        $indexName = $this->prefixedName($name);
        $task = $this->client->deleteIndex($indexName);

        return $this->waitForTask($task['taskUid']);
    }

    /**
     * Get index statistics.
     */
    public function getStats(string $name): array
    {
        return $this->getOrCreateIndex($name)->stats();
    }

    /**
     * Wait for a Meilisearch task to complete.
     *
     * @param int $taskUid The task UID returned from async operations
     * @param int $timeoutMs Maximum wait time in milliseconds
     */
    public function waitForTask(int $taskUid, int $timeoutMs = 5000): array
    {
        return $this->client->waitForTask($taskUid, $timeoutMs);
    }

    /**
     * Get the prefixed index name.
     */
    public function prefixedName(string $name): string
    {
        return $this->prefix . $name;
    }
}
```

---

## Service Provider

```php
<?php

namespace Glueful\Extensions\Meilisearch;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Meilisearch\Client\ClientFactory;
use Glueful\Extensions\Meilisearch\Client\MeilisearchClient;
use Glueful\Extensions\Meilisearch\Contracts\SearchEngineInterface;
use Glueful\Extensions\Meilisearch\Engine\MeilisearchEngine;
use Glueful\Extensions\Meilisearch\Indexing\BatchIndexer;
use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Glueful\Extensions\Meilisearch\Console\IndexCommand;
use Glueful\Extensions\Meilisearch\Console\FlushCommand;
use Glueful\Extensions\Meilisearch\Console\StatusCommand;
use Glueful\Extensions\Meilisearch\Console\SyncCommand;
use Glueful\Extensions\Meilisearch\Console\SearchCommand;

class MeilisearchProvider extends ServiceProvider
{
    /**
     * Services to register in DI container.
     */
    public static function services(): array
    {
        return [
            // Client wrapper
            MeilisearchClient::class => [
                'class' => MeilisearchClient::class,
                'shared' => true,
                'factory' => [ClientFactory::class, 'create'],
            ],

            // Search engine implementation
            SearchEngineInterface::class => [
                'class' => MeilisearchEngine::class,
                'shared' => true,
                'autowire' => true,
            ],
            MeilisearchEngine::class => [
                'class' => MeilisearchEngine::class,
                'shared' => true,
                'autowire' => true,
            ],

            // Index manager
            IndexManager::class => [
                'class' => IndexManager::class,
                'shared' => true,
                'autowire' => true,
            ],

            // Batch indexer
            BatchIndexer::class => [
                'class' => BatchIndexer::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * Register configuration.
     */
    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('meilisearch', require __DIR__ . '/../config/meilisearch.php');
    }

    /**
     * Boot the extension.
     */
    public function boot(ApplicationContext $context): void
    {
        // Register extension metadata
        try {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'meilisearch',
                'name' => 'Meilisearch',
                'version' => '1.0.0',
                'description' => 'Full-text search powered by Meilisearch',
            ]);
        } catch (\Throwable $e) {
            error_log('[Meilisearch] Failed to register metadata: ' . $e->getMessage());
        }

        // Auto-discover and register console commands from Console/ directory
        // Commands must have #[AsCommand] attribute to be discovered
        $this->discoverCommands(
            'Glueful\\Extensions\\Meilisearch\\Console',
            __DIR__ . '/Console'
        );

        // Load routes (optional search API)
        try {
            $this->loadRoutesFrom(__DIR__ . '/routes.php');
        } catch (\Throwable $e) {
            error_log('[Meilisearch] Failed to load routes: ' . $e->getMessage());
            $env = (string) ($_ENV['APP_ENV'] ?? 'production');
            if ($env !== 'production') {
                throw $e;
            }
        }
    }
}
```

---

## CLI Commands

### 1. Index Command

```bash
# Index all searchable models
php glueful search:index

# Index specific model
php glueful search:index --model=App\\Models\\Post

# Index with fresh (clear first)
php glueful search:index --fresh

# Index specific IDs
php glueful search:index --model=App\\Models\\Post --id=123,456,789
```

### 2. Flush Command

```bash
# Flush specific index
php glueful search:flush posts

# Flush all indexes
php glueful search:flush --all

# Flush with confirmation skip (for CI)
php glueful search:flush posts --force
```

### 3. Status Command

```bash
# Show all index stats
php glueful search:status

# Show specific index stats
php glueful search:status posts

# Output as JSON
php glueful search:status --json
```

**Output:**
```
Meilisearch Status
==================

Connection: http://127.0.0.1:7700 ✓

Index: posts
  Documents: 1,234
  Primary Key: uuid
  Is Indexing: No
  Last Update: 2026-02-03 10:30:45

Index: users
  Documents: 567
  Primary Key: uuid
  Is Indexing: No
  Last Update: 2026-02-03 09:15:22
```

### 4. Sync Settings Command

```bash
# Sync all index settings from models
php glueful search:sync

# Sync specific model's settings
php glueful search:sync --model=App\\Models\\Post

# Show settings diff without applying
php glueful search:sync --dry-run
```

### 5. Search Command (Debug)

```bash
# Interactive search
php glueful search:search posts "search query"

# With filters
php glueful search:search posts "query" --filter="status = published"

# With limit
php glueful search:search posts "query" --limit=5

# Raw JSON output
php glueful search:search posts "query" --raw
```

---

## API Routes (Optional)

```php
<?php

use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;

/** @var Router $router */

$router->group(['prefix' => '/api/search', 'middleware' => ['auth']], function (Router $router) {

    // Universal search endpoint
    $router->get('/', function (Request $request) use ($router) {
        $controller = container($router->getContext())->get(SearchController::class);
        return $controller->search($request);
    });

    // Search specific index
    $router->get('/{index}', function (Request $request, string $index) use ($router) {
        $controller = container($router->getContext())->get(SearchController::class);
        return $controller->searchIndex($request, $index);
    });

    // Index status (admin only)
    $router->get('/admin/status', function (Request $request) use ($router) {
        $controller = container($router->getContext())->get(SearchController::class);
        return $controller->status($request);
    })->middleware(['admin']);

});
```

---

## Usage Examples

### Basic Model Setup

```php
<?php

namespace App\Models;

use Glueful\Database\Model\Model;
use Glueful\Extensions\Meilisearch\Model\Searchable;

class Post extends Model
{
    use Searchable;

    protected string $table = 'posts';

    /**
     * Get the data to be indexed.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'body' => $this->body,
            'excerpt' => $this->excerpt,
            'author_name' => $this->author->name ?? null,
            'tags' => $this->tags->pluck('name')->toArray(),
            'category' => $this->category?->name,
            'status' => $this->status,
            'published_at' => $this->published_at?->timestamp,
            'views' => $this->views,
        ];
    }

    /**
     * Filterable attributes for Meilisearch.
     */
    public function getSearchableFilterableAttributes(): array
    {
        return ['status', 'category', 'tags', 'author_name', 'published_at'];
    }

    /**
     * Sortable attributes for Meilisearch.
     */
    public function getSearchableSortableAttributes(): array
    {
        return ['published_at', 'views', 'title'];
    }

    /**
     * Custom index settings.
     */
    public function searchableSettings(): array
    {
        return [
            'searchableAttributes' => ['title', 'body', 'excerpt', 'tags'],
            'rankingRules' => [
                'words',
                'typo',
                'proximity',
                'attribute',
                'sort',
                'exactness',
                'published_at:desc',
            ],
        ];
    }

    /**
     * Only index published posts.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->status === 'published';
    }
}
```

### Searching

```php
// Simple search
$results = Post::search($context, 'laravel tutorial')->get();

// With filters
$results = Post::search($context, 'php')
    ->where('status', 'published')
    ->where('published_at', '>=', strtotime('-30 days'))
    ->whereIn('category', ['tutorials', 'guides'])
    ->get();

// With sorting
$results = Post::search($context, 'api design')
    ->orderBy('published_at', 'desc')
    ->limit(20)
    ->get();

// Pagination
$results = Post::search($context, 'docker')
    ->where('status', 'published')
    ->paginate(page: 1, perPage: 15);

// With facets
$results = Post::search($context, '')
    ->facets(['category', 'tags', 'author_name'])
    ->where('status', 'published')
    ->get();

echo $results->facets('category');
// ['tutorials' => 45, 'guides' => 23, 'news' => 12]

// Geo search (for location-based models)
$results = Store::search($context, 'coffee')
    ->whereGeoRadius(40.7128, -74.0060, 5000) // 5km radius from NYC
    ->orderByGeo(40.7128, -74.0060) // nearest first
    ->get();

// Get raw results without model hydration
$raw = Post::search($context, 'keyword')->raw();

// Highlighting
$results = Post::search($context, 'important topic')
    ->highlight(['title', 'body'])
    ->get();

// In controller with API response
public function search(Request $request): Response
{
    $query = $request->query->get('q', '');
    $page = (int) $request->query->get('page', 1);

    $results = Post::search($context, $query)
        ->where('status', 'published')
        ->facets(['category', 'tags'])
        ->paginate($page, 20);

    return response()->json($results->toArray());
}
```

### Manual Indexing

```php
// Index a single model
$post = Post::find($uuid);
$post->searchableSync();

// Remove from index
$post->searchableRemove();

// Batch index via indexer
$indexer = app(BatchIndexer::class);
$posts = Post::where('status', 'published')->cursor();
$indexer->indexMany($posts, Post::class);

// Index manager for direct operations
$manager = app(IndexManager::class);
$manager->createIndex('posts', 'uuid');
$manager->updateSettings('posts', [
    'filterableAttributes' => ['status', 'category'],
]);
```

---

## Implementation Phases

### Phase 1: Core Foundation (Week 1)

- [ ] Project setup (composer.json, directory structure)
- [ ] Configuration file
- [ ] MeilisearchClient wrapper
- [ ] ClientFactory with connection handling
- [ ] SearchEngineInterface contract
- [ ] MeilisearchEngine implementation
- [ ] Basic unit tests

### Phase 2: Model Integration (Week 2)

- [ ] Searchable trait
- [ ] SearchableInterface contract
- [ ] DocumentBuilder for data transformation
- [ ] SearchQuery builder (basic)
- [ ] SearchResult class
- [ ] Integration tests with mock Meilisearch

### Phase 3: Advanced Querying (Week 3)

- [ ] FilterBuilder with all operators
- [ ] Facet support
- [ ] Geo-search support
- [ ] Sorting and pagination
- [ ] Highlighting
- [ ] Query builder tests

### Phase 4: Index Management (Week 4)

- [ ] IndexManager class
- [ ] BatchIndexer with chunking
- [ ] Queue support for async indexing
- [ ] Index settings synchronization
- [ ] Index lifecycle management

### Phase 5: CLI & Polish (Week 5)

- [ ] IndexCommand
- [ ] FlushCommand
- [ ] StatusCommand
- [ ] SyncCommand
- [ ] SearchCommand (debug)
- [ ] ServiceProvider finalization
- [ ] Documentation
- [ ] README with examples

### Phase 6: Testing & Release (Week 6)

- [ ] Full integration test suite
- [ ] Performance testing
- [ ] Edge case handling
- [ ] Error messages and logging
- [ ] CHANGELOG
- [ ] Version 1.0.0 release

---

## Testing Strategy

### Unit Tests

```php
// Test SearchQuery builder
public function testSearchQueryBuildsCorrectParams(): void
{
    $query = new SearchQuery(new Post(), 'test query');
    $query->where('status', 'published')
          ->whereIn('category', ['a', 'b'])
          ->orderBy('created_at', 'desc')
          ->limit(10);

    $params = $query->toSearchParams();

    $this->assertEquals([
        'filter' => [
            'status = "published"',
            'category IN ["a", "b"]',
        ],
        'sort' => ['created_at:desc'],
        'limit' => 10,
    ], $params);
}

// Test comparison operators format values correctly
public function testComparisonOperatorsFormatValues(): void
{
    $query = new SearchQuery(new Post(), '');
    $query->where('price', '>', 100)
          ->where('name', '>=', 'Apple')
          ->where('rating', '<', 5);

    $params = $query->toSearchParams();

    $this->assertEquals([
        'filter' => [
            'price > 100',
            'name >= "Apple"',
            'rating < 5',
        ],
    ], $params);
}

// Test geo filter syntax
public function testGeoRadiusFilterSyntax(): void
{
    $query = new SearchQuery(new Store(), '');
    $query->whereGeoRadius(40.7128, -74.0060, 5000);

    $params = $query->toSearchParams();

    $this->assertEquals([
        'filter' => ['_geoRadius(40.7128, -74.006, 5000)'],
    ], $params);
}

public function testGeoBoundingBoxFilterSyntax(): void
{
    $query = new SearchQuery(new Store(), '');
    $query->whereGeoBoundingBox([45.0, -73.0], [40.0, -74.0]);

    $params = $query->toSearchParams();

    $this->assertEquals([
        'filter' => ['_geoBoundingBox([45, -73], [40, -74])'],
    ], $params);
}

public function testGeoSortSyntax(): void
{
    $query = new SearchQuery(new Store(), 'coffee');
    $query->orderByGeo(40.7128, -74.0060, 'asc');

    $params = $query->toSearchParams();

    $this->assertEquals([
        'sort' => ['_geoPoint(40.7128, -74.006):asc'],
    ], $params);
}

// Test searchable key name detection
public function testGetSearchableKeyNameReturnsUuidWhenPresent(): void
{
    $model = new class {
        use Searchable;
        public string $uuid = 'abc123';
        public int $id = 1;
    };

    $this->assertEquals('uuid', $model->getSearchableKeyName());
    $this->assertEquals('abc123', $model->getSearchableId());
}

public function testGetSearchableKeyNameReturnsIdWhenNoUuid(): void
{
    $model = new class {
        use Searchable;
        public int $id = 42;
    };

    $this->assertEquals('id', $model->getSearchableKeyName());
    $this->assertEquals(42, $model->getSearchableId());
}

// Test toSearchableArray always includes 'id' field
public function testToSearchableArrayIncludesIdField(): void
{
    $model = new class {
        use Searchable;
        public string $uuid = 'xyz789';
        public string $name = 'Test';

        public function toArray(): array
        {
            return ['uuid' => $this->uuid, 'name' => $this->name];
        }
    };

    $searchable = $model->toSearchableArray();

    $this->assertArrayHasKey('id', $searchable);
    $this->assertEquals('xyz789', $searchable['id']);
}
```

### Integration Tests

```php
// Test actual Meilisearch integration
public function testIndexAndSearch(): void
{
    // Index test data
    $post = Post::factory()->create(['title' => 'Unique Test Title']);
    $post->searchableSync();

    // Wait for indexing
    sleep(1);

    // Search
    $results = Post::search($context, 'Unique Test Title')->get();

    $this->assertEquals(1, $results->count());
    $this->assertEquals($post->uuid, $results->first()->uuid);
}

// Test UUID primary key hydration
public function testModelsHydratedCorrectlyWithUuidPrimaryKey(): void
{
    // Create multiple posts with UUIDs
    $post1 = Post::factory()->create(['title' => 'First Post']);
    $post2 = Post::factory()->create(['title' => 'Second Post']);
    $post3 = Post::factory()->create(['title' => 'Third Post']);

    // Index all
    $post1->searchableSync();
    $post2->searchableSync();
    $post3->searchableSync();

    // Wait for indexing
    $this->waitForIndexing('posts');

    // Search and verify hydration
    $results = Post::search($context, 'Post')->get();

    $this->assertCount(3, $results->models());

    // Verify each model is correctly hydrated with all attributes
    foreach ($results as $model) {
        $this->assertInstanceOf(Post::class, $model);
        $this->assertNotEmpty($model->uuid);
        $this->assertNotEmpty($model->title);
    }

    // Verify order matches search relevance (first result should be most relevant)
    $models = $results->models();
    $this->assertContains($models[0]->uuid, [$post1->uuid, $post2->uuid, $post3->uuid]);
}

// Test hydration preserves search result order
public function testHydrationPreservesSearchResultOrder(): void
{
    // Create posts with distinct relevance
    $exact = Post::factory()->create(['title' => 'Meilisearch Guide']);
    $partial = Post::factory()->create(['title' => 'Guide to Search Engines']);
    $distant = Post::factory()->create(['title' => 'Database Optimization']);

    $exact->searchableSync();
    $partial->searchableSync();
    $distant->searchableSync();

    $this->waitForIndexing('posts');

    // Search for 'Meilisearch' - exact match should be first
    $results = Post::search($context, 'Meilisearch')->get();

    $models = $results->models();
    $this->assertEquals($exact->uuid, $models[0]->uuid);
}

// Test geo search with real coordinates
public function testGeoSearchReturnsNearbyResults(): void
{
    // Create stores at known locations
    $nyc = Store::factory()->create([
        'name' => 'NYC Coffee',
        'latitude' => 40.7128,
        'longitude' => -74.0060,
    ]);
    $boston = Store::factory()->create([
        'name' => 'Boston Coffee',
        'latitude' => 42.3601,
        'longitude' => -71.0589,
    ]);
    $la = Store::factory()->create([
        'name' => 'LA Coffee',
        'latitude' => 34.0522,
        'longitude' => -118.2437,
    ]);

    $nyc->searchableSync();
    $boston->searchableSync();
    $la->searchableSync();

    $this->waitForIndexing('stores');

    // Search within 500km of NYC - should find NYC and Boston, not LA
    $results = Store::search($context, 'Coffee')
        ->whereGeoRadius(40.7128, -74.0060, 500000) // 500km
        ->get();

    $uuids = array_column($results->all(), 'id');

    $this->assertContains($nyc->uuid, $uuids);
    $this->assertContains($boston->uuid, $uuids);
    $this->assertNotContains($la->uuid, $uuids);
}

// Test geo sort returns nearest first
public function testGeoSortOrdersByDistance(): void
{
    $close = Store::factory()->create([
        'name' => 'Close Store',
        'latitude' => 40.7580, // Times Square
        'longitude' => -73.9855,
    ]);
    $far = Store::factory()->create([
        'name' => 'Far Store',
        'latitude' => 40.6892, // Statue of Liberty
        'longitude' => -74.0445,
    ]);

    $close->searchableSync();
    $far->searchableSync();

    $this->waitForIndexing('stores');

    // Sort by distance from Empire State Building
    $results = Store::search($context, '')
        ->orderByGeo(40.7484, -73.9857, 'asc')
        ->get();

    $models = $results->models();

    // Close store should be first (nearer to Empire State)
    $this->assertEquals($close->uuid, $models[0]->uuid);
    $this->assertEquals($far->uuid, $models[1]->uuid);
}

// Test transaction rollback doesn't index
public function testRollbackDoesNotIndex(): void
{
    $this->expectException(\Exception::class);

    DB::transaction(function () {
        $post = Post::factory()->create(['title' => 'Rollback Test']);
        // This should queue indexing for after-commit

        throw new \Exception('Rollback!');
    });

    // Wait to ensure no async indexing
    sleep(1);

    // Search should find nothing
    $results = Post::search($context, 'Rollback Test')->get();
    $this->assertCount(0, $results->all());
}

// Test committed transaction indexes correctly
public function testCommittedTransactionIndexes(): void
{
    DB::transaction(function () {
        $post = Post::factory()->create(['title' => 'Committed Test']);
        // Indexing deferred until commit
    });

    // Wait for after-commit indexing
    $this->waitForIndexing('posts');

    $results = Post::search($context, 'Committed Test')->get();
    $this->assertCount(1, $results->all());
}

// Test queue + transaction: no job enqueued on rollback
public function testQueuedIndexingNotEnqueuedOnRollback(): void
{
    // Enable queue mode for this test
    config(['meilisearch.queue.enabled' => true]);

    // Track dispatched jobs
    Queue::fake();

    $this->expectException(\Exception::class);

    try {
        DB::transaction(function () {
            $post = Post::factory()->create(['title' => 'Queue Rollback Test']);
            // This triggers queueSearchableSync() which should defer dispatch

            throw new \Exception('Rollback!');
        });
    } catch (\Exception $e) {
        // Transaction rolled back - job should NOT have been dispatched
        Queue::assertNotPushed(SyncSearchableJob::class);
        throw $e;
    }
}

// Test queue + transaction: job enqueued only after commit
public function testQueuedIndexingEnqueuedAfterCommit(): void
{
    // Enable queue mode for this test
    config(['meilisearch.queue.enabled' => true]);

    // Track dispatched jobs
    Queue::fake();

    $post = null;

    DB::transaction(function () use (&$post) {
        $post = Post::factory()->create(['title' => 'Queue Commit Test']);
        // Job dispatch is deferred via afterCommit

        // At this point, job should NOT be dispatched yet
        Queue::assertNotPushed(SyncSearchableJob::class);
    });

    // After transaction commits, job should be dispatched
    Queue::assertPushed(SyncSearchableJob::class, function ($job) use ($post) {
        return $job->model->uuid === $post->uuid;
    });
}

/**
 * Helper to wait for Meilisearch indexing to complete.
 *
 * Uses getTasks() with filters to find pending tasks for the index.
 * Meilisearch tasks are async - getTask() requires a specific task UID,
 * so we query all pending/processing tasks for the index instead.
 *
 * @param string $index Index name to wait for
 * @param int $timeoutMs Maximum wait time in milliseconds
 */
private function waitForIndexing(string $index, int $timeoutMs = 5000): void
{
    $client = app(MeilisearchClient::class);
    $start = microtime(true) * 1000;
    $prefixedIndex = config('meilisearch.prefix', '') . $index;

    while ((microtime(true) * 1000) - $start < $timeoutMs) {
        // Query tasks for this specific index that are still processing
        $tasks = $client->getTasks([
            'indexUids' => [$prefixedIndex],
            'statuses' => ['enqueued', 'processing'],
            'limit' => 1,
        ]);

        // If no pending/processing tasks, indexing is complete
        if (empty($tasks['results'])) {
            return;
        }

        usleep(100000); // 100ms
    }

    $this->fail("Indexing did not complete within {$timeoutMs}ms");
}

/**
 * Alternative: Wait for a specific task UID to complete.
 *
 * Use this when you have the task UID from an indexing operation.
 *
 * @param int $taskUid The task UID from addDocuments, updateSettings, etc.
 * @param int $timeoutMs Maximum wait time in milliseconds
 */
private function waitForTask(int $taskUid, int $timeoutMs = 5000): array
{
    $client = app(MeilisearchClient::class);
    return $client->waitForTask($taskUid, $timeoutMs);
}
```

### Test Models for Geo Search

```php
// tests/Fixtures/Store.php
class Store extends Model
{
    use Searchable;

    protected string $table = 'stores';

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            '_geo' => [
                'lat' => (float) $this->latitude,
                'lng' => (float) $this->longitude,
            ],
        ];
    }

    public function getSearchableFilterableAttributes(): array
    {
        return ['_geo', 'category'];
    }

    public function getSearchableSortableAttributes(): array
    {
        return ['_geo', 'name'];
    }
}
```

---

## Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| `meilisearch/meilisearch-php` | ^1.6 | Official Meilisearch PHP SDK |
| `glueful/framework` | ^1.22 | Framework dependency |

---

## Environment Variables

```env
# Meilisearch connection
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=your-master-key

# Index prefix (optional)
MEILISEARCH_PREFIX=myapp_

# Queue configuration
MEILISEARCH_QUEUE=false
MEILISEARCH_QUEUE_CONNECTION=redis
MEILISEARCH_QUEUE_NAME=search

# Batch configuration
MEILISEARCH_BATCH_SIZE=500
MEILISEARCH_BATCH_TIMEOUT=30

# Search defaults
MEILISEARCH_DEFAULT_LIMIT=20
MEILISEARCH_SOFT_DELETE=true
```

---

## Related Documentation

- [Meilisearch Documentation](https://www.meilisearch.com/docs)
- [Meilisearch PHP SDK](https://github.com/meilisearch/meilisearch-php)
- [Glueful Extensions Guide](./EXTENSIONS.md)
- [Glueful Framework Improvements](./FRAMEWORK_IMPROVEMENTS.md)

---

## Changelog

### v1.0.0 (Planned)

- Initial release
- Searchable trait for models
- Full query builder with filters, facets, geo-search
- CLI commands for index management
- Queue support for async indexing
- Comprehensive documentation
