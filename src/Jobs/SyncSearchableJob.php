<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Glueful\Extensions\Meilisearch\Contracts\SearchEngineInterface;
use Glueful\Queue\Job;

class SyncSearchableJob extends Job
{
    public function handle(): void
    {
        $data = $this->getData();
        $action = $data['action'] ?? 'index';
        $modelClass = $data['model'] ?? null;
        $id = $data['id'] ?? null;
        $index = $data['index'] ?? null;

        if (!is_string($modelClass) || $id === null || !is_string($index)) {
            return;
        }

        $context = $this->context instanceof ApplicationContext
            ? $this->context
            : null;

        if ($context === null) {
            return;
        }

        if (!class_exists($modelClass)) {
            return;
        }

        if ($action === 'remove') {
            $manager = app($context, IndexManager::class);
            $idx = $manager->getOrCreateIndex($index);
            $idx->deleteDocument($id);
            return;
        }

        // index action
        if (method_exists($modelClass, 'find')) {
            $model = $modelClass::find($context, $id);
            if ($model !== null) {
                app($context, SearchEngineInterface::class)->index($model);
            }
        }
    }
}
