<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Client;

use Meilisearch\Client;

/**
 * Wrapper around the official Meilisearch PHP client.
 *
 * Provides additional functionality like index prefixing and
 * configuration-aware client instantiation.
 */
class MeilisearchClient extends Client
{
    private string $prefix;

    public function __construct(string $url, ?string $apiKey = null, string $prefix = '')
    {
        parent::__construct($url, $apiKey);
        $this->prefix = $prefix;
    }

    /**
     * Get the index prefix.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get a prefixed index name.
     */
    public function prefixedIndexName(string $name): string
    {
        return $this->prefix . $name;
    }

    /**
     * Get an index with the configured prefix.
     */
    public function getPrefixedIndex(string $name): \Meilisearch\Endpoints\Indexes
    {
        return $this->index($this->prefixedIndexName($name));
    }
}
