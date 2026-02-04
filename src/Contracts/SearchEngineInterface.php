<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Contracts;

use Glueful\Extensions\Meilisearch\Query\SearchQuery;

/**
 * Interface for search engine implementations.
 */
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
