<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Controllers;

use Glueful\Extensions\Meilisearch\Client\MeilisearchClient;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

class SearchController
{
    public function __construct(
        private readonly MeilisearchClient $client,
    ) {
    }

    public function search(Request $request): Response
    {
        $index = (string) $request->query->get('index', '');
        $query = (string) $request->query->get('q', '');
        if ($index === '') {
            return Response::error('Missing index', Response::HTTP_BAD_REQUEST);
        }

        $params = $request->query->all();
        unset($params['index'], $params['q']);

        $prefixedIndex = $this->client->prefixedIndexName($index);
        /** @var \Meilisearch\Search\SearchResult $result */
        $result = $this->client->index($prefixedIndex)->search($query, $params);
        return Response::success($result->toArray());
    }

    public function searchIndex(Request $request): Response
    {
        $index = $request->attributes->get('index', '');
        $query = (string) $request->query->get('q', '');
        $params = $request->query->all();
        unset($params['q']);

        $prefixedIndex = $this->client->prefixedIndexName($index);
        /** @var \Meilisearch\Search\SearchResult $result */
        $result = $this->client->index($prefixedIndex)->search($query, $params);
        return Response::success($result->toArray());
    }

    public function status(): Response
    {
        /** @var \Meilisearch\Contracts\IndexesResults $result */
        $result = $this->client->getIndexes();
        $indexes = [];
        foreach ($result->getResults() as $idx) {
            $indexes[] = [
                'uid' => $idx->getUid(),
                'primaryKey' => $idx->getPrimaryKey(),
                'createdAt' => $idx->getCreatedAt()?->format('c'),
                'updatedAt' => $idx->getUpdatedAt()?->format('c'),
            ];
        }
        return Response::success(['indexes' => $indexes]);
    }
}
