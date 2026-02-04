<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Contracts;

interface IndexConfigInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getIndexSettings(): array;
}
