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
    // Optional comma-separated list of allowed index names
    // Example: "posts,parps,entities"
    'allowed_indexes' => env('MEILISEARCH_ALLOWED_INDEXES', null),

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
