<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Indexing;

use Glueful\Extensions\Meilisearch\Contracts\SearchableInterface;

class DocumentBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(SearchableInterface $model): array
    {
        $data = $model->toSearchableArray();
        if (!isset($data['id'])) {
            $data['id'] = $model->getSearchableId();
        }
        return $data;
    }
}
