<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Query;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * Search result wrapper with model hydration support.
 */
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
        // Fall back to 'id' if model doesn't implement getSearchableKeyName()
        $searchableKeyField = method_exists($this->model, 'getSearchableKeyName')
            ? $this->model->getSearchableKeyName()
            : 'id';

        // Extract IDs from hits using the consistent 'id' field
        // (Meilisearch always returns 'id' as primary key)
        $ids = array_column($this->hits, 'id');

        if ($ids === []) {
            return [];
        }

        // Fetch models from database using the model's actual key
        $modelClass = get_class($this->model);

        $context = method_exists($this->model, 'getContext') ? $this->model->getContext() : null;
        if ($context === null) {
            throw new \RuntimeException('Model context is required to hydrate search results.');
        }

        $models = $modelClass::query($context)->whereIn($searchableKeyField, $ids)->get();

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
