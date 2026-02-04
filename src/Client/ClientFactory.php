<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Client;

use Glueful\Bootstrap\ApplicationContext;

final class ClientFactory
{
    public static function create(ApplicationContext $context): MeilisearchClient
    {
        $host = (string) config($context, 'meilisearch.host', 'http://127.0.0.1:7700');
        $key = config($context, 'meilisearch.key', null);
        $prefix = (string) config($context, 'meilisearch.prefix', '');

        return new MeilisearchClient($host, $key !== null ? (string) $key : null, $prefix);
    }
}
