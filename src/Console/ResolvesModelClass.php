<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Console;

/**
 * Resolves short model names (e.g. "Entity") to their fully qualified class name.
 *
 * Tries the value as-is first, then common app namespace prefixes.
 */
trait ResolvesModelClass
{
    private function resolveModelClass(string $model): string
    {
        // Already a FQCN that exists
        if (class_exists($model)) {
            return $model;
        }

        // Try common app model namespaces
        $prefixes = [
            'App\\Models\\',
            'App\\',
        ];

        foreach ($prefixes as $prefix) {
            $fqcn = $prefix . $model;
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }

        // Return as-is so the caller can report the error
        return $model;
    }
}
