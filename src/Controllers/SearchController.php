<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Meilisearch\Client\MeilisearchClient;
use Glueful\Extensions\Meilisearch\Security\SearchRequestPolicy;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Attributes\QueryParam;
use Symfony\Component\HttpFoundation\Request;

class SearchController
{
    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly ApplicationContext $context,
    ) {
    }

    /**
     * Universal search across an allowlisted index.
     */
    #[ApiOperation(
        summary: 'Universal search',
        description: 'Performs a search query across an explicitly allowlisted index. The route '
            . 'requires the `meilisearch.search` permission, applies the configured server-side '
            . 'scope filter, and only accepts configured safe search parameters.',
        tags: ['Search'],
    )]
    #[QueryParam('index', 'string', description: 'Index name to search (without prefix)', required: true)]
    #[QueryParam('q', 'string', description: 'Search query string (empty string returns all documents)')]
    #[QueryParam(
        'filter',
        'string',
        description: 'Filter expression using Meilisearch syntax; combined with the server-side scope filter'
    )]
    #[QueryParam('facets', 'string', description: 'Attributes to get facet distribution for')]
    #[QueryParam('sort', 'string', description: 'Attributes to sort by (format: attribute:direction)')]
    #[QueryParam('limit', 'integer', description: 'Maximum number of results to return (default: 20)')]
    #[QueryParam('offset', 'integer', description: 'Number of results to skip for pagination')]
    #[QueryParam(
        'attributesToRetrieve',
        'string',
        description: 'Configured retrievable attributes to include in results'
    )]
    #[QueryParam('attributesToHighlight', 'string', description: 'Attributes to highlight matches in')]
    #[ApiResponse(200, description: 'Search results retrieved successfully')]
    #[ApiResponse(400, description: 'Missing index parameter')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(403, description: 'Search permission or scope required')]
    #[ApiResponse(404, description: 'Index not found')]
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

        return $this->performSearch($request, $index, $query, $params);
    }

    /**
     * Search a specific allowlisted index.
     */
    #[ApiOperation(
        summary: 'Search specific index',
        description: 'Performs a search query on a specific allowlisted index. The route requires '
            . 'the `meilisearch.search` permission, applies the configured server-side scope '
            . 'filter, and only accepts configured safe search parameters.',
        tags: ['Search'],
    )]
    #[QueryParam('q', 'string', description: 'Search query string (empty string returns all documents)')]
    #[QueryParam(
        'filter',
        'string',
        description: 'Filter expression using Meilisearch syntax; combined with the server-side scope filter'
    )]
    #[QueryParam('facets', 'string', description: 'Attributes to get facet distribution for')]
    #[QueryParam('sort', 'string', description: 'Attributes to sort by (format: attribute:direction)')]
    #[QueryParam('limit', 'integer', description: 'Maximum number of results to return (default: 20)')]
    #[QueryParam('offset', 'integer', description: 'Number of results to skip for pagination')]
    #[QueryParam(
        'attributesToRetrieve',
        'string',
        description: 'Configured retrievable attributes to include in results'
    )]
    #[QueryParam('attributesToHighlight', 'string', description: 'Attributes to highlight matches in')]
    #[ApiResponse(200, description: 'Search results retrieved successfully')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(403, description: 'Search permission or scope required')]
    #[ApiResponse(404, description: 'Index not found')]
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

        return $this->performSearch($request, $index, $query, $params);
    }

    private function performSearch(Request $request, string $index, string $query, array $params): Response
    {
        $user = $request->attributes->get('auth.user');
        $decision = SearchRequestPolicy::fromContext($this->context)->prepare(
            $index,
            $params,
            $user instanceof UserIdentity ? $user : null
        );
        if (!$decision->allowed) {
            return Response::error($decision->message, $decision->status);
        }

        $prefixedIndex = $this->client->prefixedIndexName($index);
        /** @var \Meilisearch\Search\SearchResult $result */
        $result = $this->client->index($prefixedIndex)->search($query, $decision->params);
        return Response::success($result->toArray());
    }

    /**
     * Get index status for all Meilisearch indexes.
     */
    #[ApiOperation(
        summary: 'Get index status',
        description: 'Retrieves status information for all Meilisearch indexes including primary keys, '
            . 'creation dates, and update timestamps. Requires admin privileges.',
        tags: ['Search Admin'],
    )]
    #[ApiResponse(200, description: 'Index status retrieved successfully')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(403, description: 'Admin privileges required')]
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

    private function getAllowedIndexes(): array
    {
        $allowed = function_exists('config')
            ? config(
                $this->context,
                'meilisearch.http_search.allowed_indexes',
                config($this->context, 'meilisearch.allowed_indexes', [])
            )
            : null;

        if (is_string($allowed)) {
            $allowed = array_values(array_filter(array_map('trim', explode(',', $allowed))));
        }

        if (is_array($allowed)) {
            return $allowed;
        }

        return [];
    }
}
