<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Security;

final class SearchRequestResult
{
    /**
     * @param array<string,mixed> $params
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly int $status,
        public readonly string $message,
        public readonly array $params = [],
    ) {
    }
}
