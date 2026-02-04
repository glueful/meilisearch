# Glueful Meilisearch Extension

Full-text search integration for the Glueful Framework using [Meilisearch](https://www.meilisearch.com/).

## Overview

The Meilisearch extension provides seamless integration between Glueful Framework and Meilisearch, an open-source, lightning-fast search engine. This extension enables models to be searchable with minimal configuration while providing advanced search features like typo tolerance, filtering, faceting, and geo-search.

## Features

- **Searchable trait**: Make any model searchable with a simple trait
- **Automatic syncing**: Keep search index in sync with database changes via model events
- **Transaction-safe indexing**: Indexing deferred until after database transactions commit
- **Fluent query builder**: Intuitive API for building complex search queries
- **Filters and facets**: Full support for Meilisearch filtering and faceted search
- **Geo-search**: Location-based search with radius and bounding box filters
- **Pagination**: Built-in pagination with metadata
- **Queue support**: Optional async indexing via queue workers
- **Batch operations**: Efficient bulk indexing with configurable batch sizes
- **Index prefixing**: Multi-tenant friendly with configurable index prefixes
- **CLI commands**: Index management and debugging tools

## Installation

### Installation (Recommended)

**Install via Composer**

```bash
composer require glueful/meilisearch

# Rebuild the extensions cache after adding new packages
php glueful extensions:cache
```

Glueful auto-discovers packages of type `glueful-extension` and boots their service providers.

Enable/disable in development (these commands edit `config/extensions.php`):

```bash
# Enable the extension (adds to config/extensions.php)
php glueful extensions:enable Meilisearch

# Disable the extension (comments out in config/extensions.php)
php glueful extensions:disable Meilisearch

# Preview changes without writing
php glueful extensions:enable Meilisearch --dry-run

# Create backup before editing
php glueful extensions:enable Meilisearch --backup
```

### Local Development Installation

If you're working locally (without Composer), place the extension in `extensions/meilisearch`, ensure `config/extensions.php` has `local_path` pointing to `extensions` (non-prod).

### Verify Installation

Check status and details:

```bash
php glueful extensions:list
php glueful extensions:info Meilisearch
```

## Requirements

- PHP 8.3 or higher
- Glueful Framework 1.27.0 or higher
- Meilisearch server (v1.6+ recommended)
- meilisearch/meilisearch-php ^1.6 (installed automatically)

### Installing Meilisearch Server

The extension requires a running Meilisearch server. Choose one of the following installation methods:

**Docker (Recommended)**

```bash
docker run -d -p 7700:7700 \
  -v $(pwd)/meili_data:/meili_data \
  -e MEILI_MASTER_KEY='your-master-key' \
  getmeili/meilisearch:v1.6
```

**Homebrew (macOS)**

```bash
brew install meilisearch
meilisearch --master-key="your-master-key"
```

**Binary Download**

Download the latest binary for your platform from the [Meilisearch releases page](https://github.com/meilisearch/meilisearch/releases) or follow the [official installation guide](https://www.meilisearch.com/docs/learn/getting_started/installation).

```bash
# Example for Linux
curl -L https://install.meilisearch.com | sh
./meilisearch --master-key="your-master-key"
```

**Meilisearch Cloud**

For production, consider [Meilisearch Cloud](https://www.meilisearch.com/cloud) for a fully managed solution.

## Configuration

Set the following environment variables in your `.env` file:

```env
# Meilisearch connection
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=your-master-key

# Index prefix (optional, useful for multi-tenant or staging/production separation)
MEILISEARCH_PREFIX=myapp_

# Queue configuration (optional, for async indexing)
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

### Configuration File

The extension configuration is located at `config/meilisearch.php`:

```php
<?php

return [
    'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
    'key' => env('MEILISEARCH_KEY', null),
    'prefix' => env('MEILISEARCH_PREFIX', ''),

    'queue' => [
        'enabled' => (bool) env('MEILISEARCH_QUEUE', false),
        'connection' => env('MEILISEARCH_QUEUE_CONNECTION', null),
        'queue' => env('MEILISEARCH_QUEUE_NAME', 'search'),
    ],

    'batch' => [
        'size' => (int) env('MEILISEARCH_BATCH_SIZE', 500),
        'timeout' => (int) env('MEILISEARCH_BATCH_TIMEOUT', 30),
    ],

    'soft_delete' => (bool) env('MEILISEARCH_SOFT_DELETE', true),

    'search' => [
        'limit' => (int) env('MEILISEARCH_DEFAULT_LIMIT', 20),
        'attributes_to_highlight' => ['*'],
        'highlight_pre_tag' => '<em>',
        'highlight_post_tag' => '</em>',
    ],
];
```

## Usage

### Making Models Searchable

Add the `Searchable` trait to any model. Implementing `SearchableInterface` is recommended for static analysis type checking:

```php
<?php

namespace App\Models;

use Glueful\Database\ORM\Model;
use Glueful\Extensions\Meilisearch\Contracts\SearchableInterface;
use Glueful\Extensions\Meilisearch\Model\Searchable;

class Post extends Model implements SearchableInterface
{
    use Searchable;

    protected string $table = 'posts';

    /**
     * Customize the data indexed in Meilisearch.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'body' => $this->body,
            'author_name' => $this->author->name ?? null,
            'tags' => $this->tags->pluck('name')->toArray(),
            'category' => $this->category?->name,
            'status' => $this->status,
            'published_at' => $this->published_at?->timestamp,
        ];
    }

    /**
     * Define filterable attributes.
     */
    public function getSearchableFilterableAttributes(): array
    {
        return ['status', 'category', 'tags', 'author_name', 'published_at'];
    }

    /**
     * Define sortable attributes.
     */
    public function getSearchableSortableAttributes(): array
    {
        return ['published_at', 'title'];
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

### Basic Searching

```php
// $context is an ApplicationContext instance
// Simple search
$results = Post::search($context, 'laravel tutorial')->get();

// Access results
foreach ($results as $post) {
    echo $post->title;
}

// Get raw hits without model hydration
$rawResults = Post::search($context, 'laravel')->raw();
```

### Filtering

```php
// Single filter
$results = Post::search($context, 'php')
    ->where('status', 'published')
    ->get();

// Multiple filters
$results = Post::search($context, 'api')
    ->where('status', 'published')
    ->where('published_at', '>=', strtotime('-30 days'))
    ->whereIn('category', ['tutorials', 'guides'])
    ->get();

// Using raw filter syntax
$results = Post::search($context, 'docker')
    ->filter('status = "published" AND category IN ["tutorials", "guides"]')
    ->get();
```

### Sorting

```php
$results = Post::search($context, 'api design')
    ->orderBy('published_at', 'desc')
    ->get();

// Multiple sort criteria
$results = Post::search($context, '')
    ->orderBy('category', 'asc')
    ->orderBy('published_at', 'desc')
    ->get();
```

### Pagination

```php
$results = Post::search($context, 'docker')
    ->where('status', 'published')
    ->paginate(page: 1, perPage: 15);

// Access pagination metadata
$meta = $results->paginationMeta();
// ['current_page' => 1, 'per_page' => 15, 'total' => 42, 'total_pages' => 3, 'has_more' => true]
```

### Faceted Search

```php
$results = Post::search($context, '')
    ->facets(['category', 'tags', 'author_name'])
    ->where('status', 'published')
    ->get();

// Access facet distribution
$categoryFacets = $results->facets('category');
// ['tutorials' => 45, 'guides' => 23, 'news' => 12]

// All facets
$allFacets = $results->facets();
```

### Geo-Search

For location-based models, add geo data to your searchable array:

```php
class Store extends Model implements SearchableInterface
{
    use Searchable;

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

Search by location:

```php
// Find stores within 5km of a point
$results = Store::search($context, 'coffee')
    ->whereGeoRadius(40.7128, -74.0060, 5000)
    ->get();

// Find within bounding box
$results = Store::search($context, '')
    ->whereGeoBoundingBox([45.0, -73.0], [40.0, -74.0])
    ->get();

// Sort by distance (nearest first)
$results = Store::search($context, 'coffee')
    ->orderByGeo(40.7128, -74.0060, 'asc')
    ->get();
```

### Highlighting

```php
$results = Post::search($context, 'important topic')
    ->highlight(['title', 'body'])
    ->get();

// Access highlighted results in raw hits
$rawResults = Post::search($context, 'important')->highlight(['title'])->raw();
foreach ($rawResults['hits'] as $hit) {
    echo $hit['_formatted']['title']; // Contains <em>important</em>
}
```

### Manual Indexing

```php
// Index a single model
$post = Post::find($context, $uuid);
$post->searchableSync();

// Remove from index
$post->searchableRemove();

// Batch indexing via BatchIndexer
$indexer = app($context, BatchIndexer::class);
$posts = Post::query($context)->where('status', 'published')->get();
$indexer->indexMany($posts);
```

### Index Management

```php
use Glueful\Extensions\Meilisearch\Indexing\IndexManager;

$manager = app($context, IndexManager::class);

// Create index with settings
$manager->createIndex('posts');

// Update index settings
$manager->updateSettings('posts', [
    'filterableAttributes' => ['status', 'category'],
    'sortableAttributes' => ['published_at', 'title'],
    'searchableAttributes' => ['title', 'body', 'tags'],
]);

// Sync settings from model
$manager->syncSettingsForModel(new Post([], $context));

// Get index statistics
$stats = $manager->getStats('posts');

// Delete all documents from index
$manager->flush('posts');

// Delete the index entirely
$manager->deleteIndex('posts');
```

## CLI Commands

### Index Models

```bash
# Index all records for a model
php glueful search:index --model=App\\Models\\Post

# Index specific IDs
php glueful search:index --model=App\\Models\\Post --id=uuid1,uuid2,uuid3

# Fresh index (clear before indexing)
php glueful search:index --model=App\\Models\\Post --fresh
```

### Check Index Status

```bash
# Show all indexes
php glueful search:status

# Show specific index stats
php glueful search:status posts

# Output as JSON
php glueful search:status --json
```

### Sync Index Settings

```bash
# Sync settings from model to Meilisearch
php glueful search:sync --model=App\\Models\\Post

# Dry run (show settings without applying)
php glueful search:sync --model=App\\Models\\Post --dry-run
```

### Flush Index

```bash
# Flush specific index
php glueful search:flush posts

# Flush all indexes
php glueful search:flush --all

# Skip confirmation
php glueful search:flush posts --force
```

### Debug Search

```bash
# Search an index
php glueful search:search posts "search query"

# With filters
php glueful search:search posts "query" --filter="status = published"

# Limit results
php glueful search:search posts "query" --limit=5

# Raw JSON output
php glueful search:search posts "query" --raw
```

## API Endpoints

All endpoints are prefixed with `/api/search` and require authentication.

### Search

- `GET /api/search?index={index}&q={query}` - Universal search
- `GET /api/search/{index}?q={query}` - Search specific index

Query parameters:
- `q` - Search query (optional, empty returns all)
- `filter` - Filter expression
- `facets` - Attributes for facet distribution
- `sort` - Sort criteria
- `limit` - Maximum results (default: 20)
- `offset` - Pagination offset

### Admin

- `GET /api/search/admin/status` - Get all index status (requires admin middleware)

## Transaction-Safe Indexing

The extension automatically defers indexing operations until after database transactions commit:

```php
// Using db() helper with transaction()
db($context)->transaction(function () use ($context) {
    $post = Post::create($context, [
        'title' => 'New Post',
        'body' => 'Content here...',
    ]);
    // Indexing is deferred, not executed yet
});
// After commit, the post is indexed

// If transaction rolls back, nothing is indexed
try {
    db($context)->transaction(function () use ($context) {
        $post = Post::create($context, ['title' => 'Will be rolled back']);
        throw new \Exception('Rollback!');
    });
} catch (\Exception $e) {
    // Transaction rolled back - post is NOT indexed
}
```

## Queue Support

Enable queue-based indexing for better performance in production:

```env
MEILISEARCH_QUEUE=true
MEILISEARCH_QUEUE_CONNECTION=redis
MEILISEARCH_QUEUE_NAME=search
```

When enabled, indexing operations are dispatched to the queue after transaction commit, ensuring both data consistency and non-blocking request handling.

Run the queue worker:

```bash
php glueful queue:work --queue=search
```

## Primary Key Strategy

The extension uses `id` as the Meilisearch primary key field name for all indexes. The model's actual key (`uuid` or `id`) is mapped to this field:

- Models with `uuid` property: `uuid` value stored as `id`
- Models without `uuid`: `id` value stored as `id`

This ensures consistent behavior across all searchable models and proper document hydration.

## Performance Considerations

- **Batch size**: Configure `MEILISEARCH_BATCH_SIZE` for bulk operations (default: 500)
- **Queue indexing**: Enable for production to avoid blocking requests
- **Selective indexing**: Use `shouldBeSearchable()` to skip irrelevant records
- **Attribute selection**: Define `getSearchableFilterableAttributes()` and `getSearchableSortableAttributes()` for optimal index settings

## Troubleshooting

### Common Issues

1. **Models not appearing in search**: Ensure `shouldBeSearchable()` returns true and the model was saved after adding the trait.

2. **Filters not working**: Verify the attribute is listed in `getSearchableFilterableAttributes()` and run `php glueful search:sync`.

3. **Sort not working**: Verify the attribute is listed in `getSearchableSortableAttributes()` and run `php glueful search:sync`.

4. **Connection errors**: Check `MEILISEARCH_HOST` and `MEILISEARCH_KEY` are correct. Verify Meilisearch is running.

5. **Index not found**: The extension auto-creates indexes on first use. If issues persist, manually create with `search:index --fresh`.

### Debugging

```bash
# Check Meilisearch connection and indexes
php glueful search:status

# Test search directly
php glueful search:search posts "test query" --raw

# Verify index settings match model
php glueful search:sync --model=App\\Models\\Post --dry-run
```

## License

This extension is licensed under the same license as the Glueful Framework.

## Support

For issues, feature requests, or questions:
- Create an issue in the repository
- See [Meilisearch Documentation](https://www.meilisearch.com/docs) for search engine specifics
