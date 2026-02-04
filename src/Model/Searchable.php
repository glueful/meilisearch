<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Model;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Meilisearch\Contracts\SearchableInterface;
use Glueful\Extensions\Meilisearch\Contracts\SearchEngineInterface;
use Glueful\Queue\QueueManager;
use Glueful\Extensions\Meilisearch\Jobs\SyncSearchableJob;
use Glueful\Extensions\Meilisearch\Query\SearchQuery;

/**
 * Trait for making models searchable via Meilisearch.
 *
 * Add this trait to any model to enable full-text search capabilities.
 * The model will automatically sync to the search index on create/update/delete.
 */
trait Searchable
{
    /**
     * Boot the searchable trait.
     * Registers model event listeners for automatic syncing.
     */
    public static function bootSearchable(): void
    {
        static::created(fn ($model) => $model->queueSearchableSync());
        static::updated(fn ($model) => $model->queueSearchableSync());
        static::deleted(fn ($model) => $model->queueSearchableRemove());

        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($model) => $model->queueSearchableSync());
        }
    }

    /**
     * Queue the searchable sync operation.
     * Dispatches after transaction commits if in a transaction.
     */
    protected function queueSearchableSync(): void
    {
        $this->dispatchAfterCommit(fn () => $this->dispatchSearchJob('index'));
    }

    /**
     * Queue the searchable remove operation.
     * Dispatches after transaction commits if in a transaction.
     */
    protected function queueSearchableRemove(): void
    {
        $this->dispatchAfterCommit(fn () => $this->dispatchSearchJob('remove'));
    }

    /**
     * Dispatch a search indexing job or execute immediately.
     */
    protected function dispatchSearchJob(string $action): void
    {
        $context = method_exists($this, 'getContext') ? $this->getContext() : null;
        $queueEnabled = $context !== null && function_exists('config')
            ? (bool) config($context, 'meilisearch.queue.enabled', false)
            : false;

        if ($queueEnabled && $context !== null && function_exists('app')) {
            $connection = config($context, 'meilisearch.queue.connection', null);
            $queueName = config($context, 'meilisearch.queue.queue', 'search');
            $payload = [
                'action' => $action,
                'model' => static::class,
                'id' => $this->getSearchableId(),
                'index' => $this->searchableAs(),
            ];

            app($context, QueueManager::class)->push(
                SyncSearchableJob::class,
                $payload,
                is_string($queueName) ? $queueName : null,
                is_string($connection) ? $connection : null
            );
            return;
        }

        if ($action === 'remove') {
            $this->searchableRemove();
            return;
        }

        $this->searchableSync();
    }

    /**
     * Dispatch after commit if possible, otherwise execute immediately.
     */
    protected function dispatchAfterCommit(callable $callback): void
    {
        if (function_exists('db') && method_exists($this, 'getContext')) {
            $context = $this->getContext();
            if ($context !== null) {
                try {
                    db($context)->afterCommit($callback);
                    return;
                } catch (\Throwable $e) {
                    // Fall through to immediate execution
                }
            }
        }

        $callback();
    }

    /**
     * Get the index name for this model.
     */
    public function searchableAs(): string
    {
        return $this->getTable();
    }

    /**
     * Get the document ID for search index.
     * Meilisearch requires 'id' as primary key field name.
     */
    public function getSearchableId(): string|int
    {
        return $this->{$this->getSearchableKeyName()};
    }

    /**
     * Get the model's key name used for search indexing.
     * Returns 'uuid' if model has uuid property, otherwise 'id'.
     */
    public function getSearchableKeyName(): string
    {
        return property_exists($this, 'uuid') || isset($this->uuid) ? 'uuid' : 'id';
    }

    /**
     * Get the data to be indexed.
     * Override this method to customize indexed fields.
     * Note: The returned array MUST include 'id' key for Meilisearch.
     */
    public function toSearchableArray(): array
    {
        $data = $this->toArray();

        // Ensure 'id' field exists for Meilisearch primary key
        if (!isset($data['id'])) {
            $data['id'] = $this->getSearchableId();
        }

        return $data;
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return true;
    }

    /**
     * Get searchable index settings.
     * Override to customize Meilisearch index settings.
     */
    public function searchableSettings(): array
    {
        return [];
    }

    /**
     * Get searchable attributes for filtering.
     */
    public function getSearchableFilterableAttributes(): array
    {
        return [];
    }

    /**
     * Get searchable attributes for sorting.
     */
    public function getSearchableSortableAttributes(): array
    {
        return [];
    }

    /**
     * Sync this model to search index.
     */
    public function searchableSync(): void
    {
        if (!$this->shouldBeSearchable()) {
            $this->searchableRemove();
            return;
        }

        $this->getSearchEngine()->index($this);
    }

    /**
     * Remove this model from search index.
     */
    public function searchableRemove(): void
    {
        $this->getSearchEngine()->remove($this);
    }

    /**
     * Search this model's index.
     */
    public static function search(ApplicationContext $context, string $query = ''): SearchQuery
    {
        return new SearchQuery(new static([], $context), $query);
    }

    /**
     * Get the search engine instance.
     */
    protected function getSearchEngine(): SearchEngineInterface
    {
        if (function_exists('app') && method_exists($this, 'getContext')) {
            return app($this->getContext(), SearchEngineInterface::class);
        }

        throw new \RuntimeException('Unable to resolve SearchEngineInterface from container');
    }
}
