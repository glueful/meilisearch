<?php

/**
 * Meilisearch Extension Routes
 *
 * This file defines routes for Meilisearch full-text search functionality including:
 * - Universal search across indexes
 * - Index-specific search queries
 * - Index status and statistics (admin)
 *
 * All routes in this extension require authentication middleware.
 * Admin routes require additional admin middleware for elevated access.
 *
 * @see https://www.meilisearch.com/docs for Meilisearch documentation
 */

declare(strict_types=1);

use Glueful\Extensions\Meilisearch\Controllers\SearchController;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;

/** @var Router $router Router instance injected by RouteManifest::load() */

$router->group(['prefix' => '/api/search', 'middleware' => ['auth']], function (Router $router) {

    /**
     * @route GET /api/search
     * @tag Search
     * @summary Universal search
     * @description Performs a search query across a specified index. Supports all Meilisearch
     *              search parameters including filters, facets, sorting, and pagination.
     * @requiresAuth true
     * @param index query string true "Index name to search (without prefix)"
     * @param q query string false "Search query string (empty string returns all documents)"
     * @param filter query string false "Filter expression using Meilisearch syntax"
     * @param facets query array false "Attributes to get facet distribution for"
     * @param sort query array false "Attributes to sort by (format: attribute:direction)"
     * @param limit query integer false "Maximum number of results to return (default: 20)"
     * @param offset query integer false "Number of results to skip for pagination"
     * @param attributesToRetrieve query array false "Attributes to include in results"
     * @param attributesToHighlight query array false "Attributes to highlight matches in"
     * @response 200 application/json "Search results retrieved successfully" {
     *   hits:array=[{
     *     id:string="Document primary key",
     *     _formatted:object="Highlighted version of document"
     *   }],
     *   estimatedTotalHits:integer="Estimated total matching documents",
     *   processingTimeMs:integer="Search processing time in milliseconds",
     *   query:string="The search query used",
     *   facetDistribution:object="Facet counts by attribute (if requested)"
     * }
     * @response 400 application/json "Missing index parameter"
     * @response 401 application/json "Authentication required"
     * @response 404 application/json "Index not found"
     */
    $router->get('/', function (Request $request) use ($router) {
        $controller = container($router->getContext())->get(SearchController::class);
        return $controller->search($request);
    });

    /**
     * @route GET /api/search/{index}
     * @tag Search
     * @summary Search specific index
     * @description Performs a search query on a specific index. The index name is provided
     *              as a path parameter. Supports all Meilisearch search parameters.
     * @requiresAuth true
     * @param index path string true "Index name to search (without prefix)"
     * @param q query string false "Search query string (empty string returns all documents)"
     * @param filter query string false "Filter expression using Meilisearch syntax"
     * @param facets query array false "Attributes to get facet distribution for"
     * @param sort query array false "Attributes to sort by (format: attribute:direction)"
     * @param limit query integer false "Maximum number of results to return (default: 20)"
     * @param offset query integer false "Number of results to skip for pagination"
     * @param attributesToRetrieve query array false "Attributes to include in results"
     * @param attributesToHighlight query array false "Attributes to highlight matches in"
     * @response 200 application/json "Search results retrieved successfully" {
     *   hits:array=[{
     *     id:string="Document primary key",
     *     _formatted:object="Highlighted version of document"
     *   }],
     *   estimatedTotalHits:integer="Estimated total matching documents",
     *   processingTimeMs:integer="Search processing time in milliseconds",
     *   query:string="The search query used",
     *   facetDistribution:object="Facet counts by attribute (if requested)"
     * }
     * @response 401 application/json "Authentication required"
     * @response 404 application/json "Index not found"
     */
    $router->get('/{index}', function (Request $request, string $index) use ($router) {
        $controller = container($router->getContext())->get(SearchController::class);
        return $controller->searchIndex($request, $index);
    });

    /**
     * @route GET /api/search/admin/status
     * @tag Search Admin
     * @summary Get index status
     * @description Retrieves status information for all Meilisearch indexes including
     *              primary keys, creation dates, and update timestamps. Requires admin privileges.
     * @requiresAuth true
     * @response 200 application/json "Index status retrieved successfully" {
     *   indexes:array=[{
     *     uid:string="Index unique identifier (with prefix)",
     *     primaryKey:string="Primary key field name",
     *     createdAt:string="Index creation timestamp (ISO 8601)",
     *     updatedAt:string="Last update timestamp (ISO 8601)"
     *   }]
     * }
     * @response 401 application/json "Authentication required"
     * @response 403 application/json "Admin privileges required"
     */
    $router->get('/admin/status', function (Request $request) use ($router) {
        $controller = container($router->getContext())->get(SearchController::class);
        return $controller->status($request);
    })->middleware(['admin']);

});
