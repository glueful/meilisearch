<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Query;

use Glueful\Extensions\Meilisearch\Contracts\SearchEngineInterface;

/**
 * Fluent search query builder for Meilisearch.
 */
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
        $engine = $this->getSearchEngine();
        $rawResult = $engine->search($this);

        return new SearchResult($rawResult, $this->model);
    }

    /**
     * Get raw search results without model hydration.
     */
    public function raw(): array
    {
        return $this->getSearchEngine()->search($this);
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

    private function getSearchEngine(): SearchEngineInterface
    {
        if (function_exists('app') && method_exists($this->model, 'getContext')) {
            return app($this->model->getContext(), SearchEngineInterface::class);
        }

        throw new \RuntimeException('Unable to resolve SearchEngineInterface from container');
    }
}
