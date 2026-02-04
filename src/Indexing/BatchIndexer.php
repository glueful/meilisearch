<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Indexing;

use Glueful\Extensions\Meilisearch\Contracts\SearchableInterface;
use Glueful\Extensions\Meilisearch\Engine\MeilisearchEngine;

class BatchIndexer
{
    public function __construct(
        private readonly MeilisearchEngine $engine,
        private readonly IndexManager $indexManager,
    ) {
    }

    /**
     * @param iterable<SearchableInterface> $models
     */
    public function indexMany(iterable $models): void
    {
        $batch = [];
        $indexName = null;
        $size = $this->indexManager->getBatchSize();

        foreach ($models as $model) {
            if ($indexName === null) {
                $indexName = $model->searchableAs();
            }
            $batch[] = $model;
            if (count($batch) >= $size) {
                $this->indexBatch($indexName, $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->indexBatch($indexName, $batch);
        }
    }

    /**
     * @param iterable<SearchableInterface> $models
     */
    public function removeMany(iterable $models): void
    {
        $batch = [];
        $indexName = null;
        $size = $this->indexManager->getBatchSize();

        foreach ($models as $model) {
            if ($indexName === null) {
                $indexName = $model->searchableAs();
            }
            $batch[] = $model;
            if (count($batch) >= $size) {
                $this->removeBatch($indexName, $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->removeBatch($indexName, $batch);
        }
    }

    /**
     * @param array<int, SearchableInterface> $batch
     */
    private function indexBatch(?string $indexName, array $batch): void
    {
        if ($indexName === null) {
            return;
        }
        $index = $this->indexManager->getOrCreateIndex($indexName);
        $documents = [];
        foreach ($batch as $model) {
            $documents[] = $this->indexManager->getDocumentBuilder()->build($model);
        }
        $index->addDocuments($documents);
    }

    /**
     * @param array<int, SearchableInterface> $batch
     */
    private function removeBatch(?string $indexName, array $batch): void
    {
        if ($indexName === null) {
            return;
        }
        $index = $this->indexManager->getOrCreateIndex($indexName);
        $ids = [];
        foreach ($batch as $model) {
            $ids[] = $model->getSearchableId();
        }
        if ($ids !== []) {
            $index->deleteDocuments($ids);
        }
    }
}
