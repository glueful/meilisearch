<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Indexing;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Meilisearch\Client\MeilisearchClient;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;

/**
 * Manages Meilisearch index lifecycle operations.
 */
class IndexManager
{
    private ?DocumentBuilder $documentBuilder = null;
    private int $batchSize = 500;

    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly ApplicationContext $context
    ) {
        // Load batch size from config if available
        $this->batchSize = (int) (function_exists('config')
            ? config($this->context, 'meilisearch.batch.size', 500)
            : 500);
    }

    /**
     * Create a new index with explicit primary key.
     *
     * IMPORTANT: We ALWAYS use 'id' as the Meilisearch primary key field name.
     * The model's actual key (uuid or id) is mapped to this field in toSearchableArray().
     *
     * @param string $name Index name (without prefix)
     * @param string $primaryKey Always 'id' for consistency (default)
     */
    public function createIndex(string $name, string $primaryKey = 'id'): array
    {
        $indexName = $this->client->prefixedIndexName($name);

        // Explicitly set primaryKey to 'id' to guarantee consistent document lookups
        $task = $this->client->createIndex($indexName, ['primaryKey' => $primaryKey]);

        return $this->waitForTask($task['taskUid']);
    }

    /**
     * Get or create an index.
     */
    public function getOrCreateIndex(string $name): Indexes
    {
        $indexName = $this->client->prefixedIndexName($name);

        try {
            $index = $this->client->index($indexName);

            // Validate primaryKey matches expected 'id'
            $pk = $index->fetchPrimaryKey();
            if ($pk !== null && $pk !== 'id') {
                error_log("[Meilisearch] Warning: Index '{$name}' has unexpected primaryKey '{$pk}'");
            }

            return $index;
        } catch (ApiException $e) {
            if ($e->httpStatus === 404) {
                $this->createIndex($name);
                return $this->client->index($indexName);
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
        $indexName = $this->client->prefixedIndexName($name);
        $task = $this->client->deleteIndex($indexName);

        return $this->waitForTask($task['taskUid']);
    }

    /**
     * Flush all documents from an index.
     */
    public function flush(string $name): array
    {
        $index = $this->getOrCreateIndex($name);
        $task = $index->deleteAllDocuments();

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
     * Get all indexes.
     */
    public function getAllIndexes(): array
    {
        $result = $this->client->getIndexes();
        return $result->getResults();
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
     * Get the client instance.
     */
    public function getClient(): MeilisearchClient
    {
        return $this->client;
    }

    /**
     * Get the batch size for bulk operations.
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Get the document builder instance.
     */
    public function getDocumentBuilder(): DocumentBuilder
    {
        if ($this->documentBuilder === null) {
            $this->documentBuilder = new DocumentBuilder();
        }
        return $this->documentBuilder;
    }

    /**
     * Sync index settings from a searchable model.
     *
     * Applies filterable/sortable attributes and custom settings from the model.
     *
     * @param object $model A model using the Searchable trait
     */
    public function syncSettingsForModel(object $model): array
    {
        $indexName = $model->searchableAs();
        $settings = function_exists('config')
            ? (array) config($this->context, 'meilisearch.index_settings', [])
            : [];

        // Get filterable attributes if method exists
        if (method_exists($model, 'getSearchableFilterableAttributes')) {
            $filterable = $model->getSearchableFilterableAttributes();
            if ($filterable !== []) {
                $settings['filterableAttributes'] = $filterable;
            }
        }

        // Get sortable attributes if method exists
        if (method_exists($model, 'getSearchableSortableAttributes')) {
            $sortable = $model->getSearchableSortableAttributes();
            if ($sortable !== []) {
                $settings['sortableAttributes'] = $sortable;
            }
        }

        // Get custom settings if method exists
        if (method_exists($model, 'searchableSettings')) {
            $customSettings = $model->searchableSettings();
            if ($customSettings !== []) {
                $settings = array_replace_recursive($settings, $customSettings);
            }
        }

        if ($settings === []) {
            return ['status' => 'no_settings'];
        }

        return $this->updateSettings($indexName, $settings);
    }
}
