<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Meilisearch\Client\MeilisearchClient;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

class SearchController
{
    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly ApplicationContext $context,
    ) {
    }

    public function search(Request $request): Response
    {
        $index = (string) $request->query->get('index', '');
        $error = $this->validateIndex($index, true);
        if ($error !== null) {
            return $error;
        }

        $query = (string) $request->query->get('q', '');
        $params = $request->query->all();
        unset($params['index'], $params['q']);

        return $this->performSearch($index, $query, $params);
    }

    public function searchIndex(Request $request): Response
    {
        $index = (string) $request->attributes->get('index', '');
        $error = $this->validateIndex($index, false);
        if ($error !== null) {
            return $error;
        }

        $query = (string) $request->query->get('q', '');
        $params = $request->query->all();
        unset($params['q']);

        return $this->performSearch($index, $query, $params);
    }

    private function performSearch(string $index, string $query, array $params): Response
    {
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

    private function validateIndex(string $index, bool $allowMissing): ?Response
    {
        if ($index === '') {
            return $allowMissing
                ? Response::error('Missing index', Response::HTTP_BAD_REQUEST)
                : Response::error('Index not found', Response::HTTP_NOT_FOUND);
        }

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $index)) {
            return Response::error('Invalid index', Response::HTTP_BAD_REQUEST);
        }

        $allowed = $this->getAllowedIndexes();
        if ($allowed !== null && !in_array($index, $allowed, true)) {
            return Response::error('Index not found', Response::HTTP_NOT_FOUND);
        }

        return null;
    }

    private function getAllowedIndexes(): ?array
    {
        $allowed = function_exists('config')
            ? config($this->context, 'meilisearch.allowed_indexes', null)
            : null;

        if (is_string($allowed)) {
            $allowed = array_values(array_filter(array_map('trim', explode(',', $allowed))));
        }

        if (is_array($allowed)) {
            return $allowed === [] ? null : $allowed;
        }

        return null;
    }
}
