<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Query;

final class FilterBuilder
{
    /**
     * @param array<int, string> $filters
     */
    public static function and(array $filters): string
    {
        return '(' . implode(' AND ', $filters) . ')';
    }

    /**
     * @param array<int, string> $filters
     */
    public static function or(array $filters): string
    {
        return '(' . implode(' OR ', $filters) . ')';
    }

    public static function not(string $filter): string
    {
        return 'NOT (' . $filter . ')';
    }
}
