<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Engine;

use Glueful\Extensions\Meilisearch\Client\MeilisearchClient;
use Glueful\Extensions\Meilisearch\Contracts\SearchableInterface;
use Glueful\Extensions\Meilisearch\Contracts\SearchEngineInterface;
use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Glueful\Extensions\Meilisearch\Query\SearchQuery;

/**
 * Meilisearch search engine implementation.
 */
class MeilisearchEngine implements SearchEngineInterface
{
    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly IndexManager $indexManager
    ) {}

    /**
     * Index a single model.
     */
    public function index(SearchableInterface $model): void
    {
        $index = $this->indexManager->getOrCreateIndex($model->searchableAs());
        $document = $model->toSearchableArray();

        $index->addDocuments([$document], 'id');
    }

    /**
     * Index multiple models.
     *
     * @param iterable<SearchableInterface> $models
     */
    public function indexMany(iterable $models): void
    {
        $documents = [];
        $indexName = null;

        foreach ($models as $model) {
            if ($indexName === null) {
                $indexName = $model->searchableAs();
            }
            $documents[] = $model->toSearchableArray();
        }

        if ($documents === [] || $indexName === null) {
            return;
        }

        $index = $this->indexManager->getOrCreateIndex($indexName);
        $index->addDocuments($documents, 'id');
    }

    /**
     * Remove a model from the index.
     */
    public function remove(SearchableInterface $model): void
    {
        $index = $this->indexManager->getOrCreateIndex($model->searchableAs());
        $index->deleteDocument($model->getSearchableId());
    }

    /**
     * Remove multiple models from the index.
     *
     * @param iterable<SearchableInterface> $models
     */
    public function removeMany(iterable $models): void
    {
        $ids = [];
        $indexName = null;

        foreach ($models as $model) {
            if ($indexName === null) {
                $indexName = $model->searchableAs();
            }
            $ids[] = $model->getSearchableId();
        }

        if ($ids === [] || $indexName === null) {
            return;
        }

        $index = $this->indexManager->getOrCreateIndex($indexName);
        $index->deleteDocuments($ids);
    }

    /**
     * Flush all documents from an index.
     */
    public function flush(string $index): void
    {
        $this->indexManager->flush($index);
    }

    /**
     * Perform a search query.
     *
     * @return array{hits: array, estimatedTotalHits: int, processingTimeMs: int}
     */
    public function search(SearchQuery $query): array
    {
        $model = $query->getModel();
        $indexName = method_exists($model, 'searchableAs')
            ? $model->searchableAs()
            : $model->getTable();

        $index = $this->indexManager->getOrCreateIndex($indexName);

        $result = $index->search($query->getQuery(), $query->toSearchParams());

        return [
            'hits' => $result->getHits(),
            'estimatedTotalHits' => $result->getEstimatedTotalHits(),
            'processingTimeMs' => $result->getProcessingTimeMs(),
            'facetDistribution' => $result->getFacetDistribution() ?? [],
            'facetStats' => $result->getFacetStats() ?? [],
        ];
    }

    /**
     * Update index settings.
     */
    public function updateSettings(string $index, array $settings): void
    {
        $this->indexManager->updateSettings($index, $settings);
    }

    /**
     * Get index statistics.
     */
    public function getIndexStats(string $index): array
    {
        return $this->indexManager->getStats($index);
    }

    /**
     * Get the underlying client.
     */
    public function getClient(): MeilisearchClient
    {
        return $this->client;
    }

    /**
     * Get the index manager.
     */
    public function getIndexManager(): IndexManager
    {
        return $this->indexManager;
    }
}
