<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Events;

final class SearchPerformed
{
    public function __construct(
        public readonly object $model,
        public readonly string $index,
        public readonly string $query,
    ) {
    }
}
