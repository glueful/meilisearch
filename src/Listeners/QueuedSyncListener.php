<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Listeners;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Meilisearch\Jobs\SyncSearchableJob;
use Glueful\Queue\QueueManager;

class QueuedSyncListener
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function handle(object $event): void
    {
        if (!property_exists($event, 'model')) {
            return;
        }

        $model = $event->model;
        if (!method_exists($model, 'getSearchableId') || !method_exists($model, 'searchableAs')) {
            return;
        }

        db($this->context)->afterCommit(function () use ($model) {
            $queue = app($this->context, QueueManager::class);
            $queue->push(SyncSearchableJob::class, [
                'action' => 'index',
                'model' => get_class($model),
                'id' => $model->getSearchableId(),
                'index' => $model->searchableAs(),
            ], config($this->context, 'meilisearch.queue.queue', 'search'), config($this->context, 'meilisearch.queue.connection', null));
        });
    }
}
