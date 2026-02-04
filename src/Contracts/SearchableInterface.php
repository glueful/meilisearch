<?php

declare(strict_types=1);

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
