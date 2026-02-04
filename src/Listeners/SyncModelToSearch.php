<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Listeners;

use Glueful\Extensions\Meilisearch\Contracts\SearchableInterface;
use Glueful\Extensions\Meilisearch\Contracts\SearchEngineInterface;
use Glueful\Bootstrap\ApplicationContext;

class SyncModelToSearch
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function handle(object $event): void
    {
        if (method_exists($event, 'getModel')) {
            $model = $event->getModel();
        } elseif (property_exists($event, 'model')) {
            $model = $event->model;
        } else {
            return;
        }

        if (!$model instanceof SearchableInterface) {
            return;
        }

        app($this->context, SearchEngineInterface::class)->index($model);
    }
}
