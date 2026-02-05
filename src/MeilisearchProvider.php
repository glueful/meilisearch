<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch;

use Glueful\Bootstrap\ApplicationContext;
use Psr\Container\ContainerInterface;
use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Meilisearch\Client\MeilisearchClient;
use Glueful\Extensions\Meilisearch\Client\ClientFactory;
use Glueful\Extensions\Meilisearch\Contracts\SearchEngineInterface;
use Glueful\Extensions\Meilisearch\Engine\MeilisearchEngine;
use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Glueful\Extensions\Meilisearch\Indexing\BatchIndexer;

/**
 * Meilisearch extension service provider.
 */
class MeilisearchProvider extends ServiceProvider
{
    /**
     * Services to register in DI container.
     */
    public static function services(): array
    {
        return [
            // Client wrapper - created via factory
            MeilisearchClient::class => [
                'class' => MeilisearchClient::class,
                'shared' => true,
                'factory' => [self::class, 'createClient'],
            ],

            // Index manager
            IndexManager::class => [
                'class' => IndexManager::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Batch indexer
            BatchIndexer::class => [
                'class' => BatchIndexer::class,
                'shared' => true,
                'autowire' => true,
            ],

            // Search engine implementation
            SearchEngineInterface::class => [
                'class' => MeilisearchEngine::class,
                'shared' => true,
                'autowire' => true,
            ],
            MeilisearchEngine::class => [
                'class' => MeilisearchEngine::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * Factory method to create the Meilisearch client.
     */
    public static function createClient(ContainerInterface $container): MeilisearchClient
    {
        $context = $container->get(ApplicationContext::class);
        return ClientFactory::create($context);
    }

    /**
     * Register configuration.
     */
    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('meilisearch', require __DIR__ . '/../config/meilisearch.php');
    }

    /**
     * Boot the extension.
     */
    public function boot(ApplicationContext $context): void
    {
        // Register extension metadata
        try {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'meilisearch',
                'name' => 'Meilisearch',
                'version' => '1.2.0',
                'description' => 'Full-text search powered by Meilisearch',
            ]);
        } catch (\Throwable $e) {
            error_log('[Meilisearch] Failed to register metadata: ' . $e->getMessage());
        }

        // Auto-discover CLI commands from Console/ directory
        $this->discoverCommands(
            'Glueful\\Extensions\\Meilisearch\\Console',
            __DIR__ . '/Console'
        );
    }
}
