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

/** @var Router $router Router instance injected by RouteManifest::load() */

$router->group(['prefix' => '/api/search', 'middleware' => ['auth']], function (Router $router) {

    // Universal search across an allowlisted index
    $router->get('/', [SearchController::class, 'search'])
        ->middleware('meilisearch_permission:meilisearch.search')
        ->middleware('rate_limit')
        ->rateLimit(60, 1, by: 'user');

    // Index status and statistics (admin)
    $router->get('/admin/status', [SearchController::class, 'status'])
        ->middleware(['admin']);

    // Search a specific allowlisted index
    $router->get('/{index}', [SearchController::class, 'searchIndex'])
        ->middleware('meilisearch_permission:meilisearch.search')
        ->middleware('rate_limit')
        ->rateLimit(60, 1, by: 'user');
});
