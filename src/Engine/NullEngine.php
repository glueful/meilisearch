<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Engine;

use Glueful\Extensions\Meilisearch\Contracts\SearchEngineInterface;
use Glueful\Extensions\Meilisearch\Contracts\SearchableInterface;
use Glueful\Extensions\Meilisearch\Query\SearchQuery;

class NullEngine implements SearchEngineInterface
{
    public function index(SearchableInterface $model): void {}
    public function indexMany(iterable $models): void {}
    public function remove(SearchableInterface $model): void {}
    public function removeMany(iterable $models): void {}
    public function flush(string $index): void {}
    public function search(SearchQuery $query): array
    {
        return ['hits' => [], 'estimatedTotalHits' => 0, 'processingTimeMs' => 0];
    }
    public function updateSettings(string $index, array $settings): void {}
    public function getIndexStats(string $index): array { return []; }
}
