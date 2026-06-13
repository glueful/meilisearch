<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Security;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;

final class SearchRequestPolicy
{
    /** @var list<string> */
    private array $allowedIndexes;

    /** @var list<string> */
    private array $publicIndexes;

    /** @var array<string,string> */
    private array $serverFilters;

    /** @var array<string,list<string>> */
    private array $retrievableAttributes;

    /** @var list<string> */
    private array $allowedParameters;

    private bool $requireServerFilter;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $this->allowedIndexes = $this->stringList($config['allowed_indexes'] ?? []);
        $this->publicIndexes = $this->stringList($config['public_indexes'] ?? []);
        $this->serverFilters = $this->stringMap($config['server_filters'] ?? []);
        $this->retrievableAttributes = $this->stringListMap($config['retrievable_attributes'] ?? []);
        $this->allowedParameters = $this->stringList($config['allowed_parameters'] ?? [
            'filter',
            'facets',
            'sort',
            'limit',
            'offset',
            'page',
            'hitsPerPage',
            'attributesToRetrieve',
            'attributesToHighlight',
            'showMatchesPosition',
            'matchingStrategy',
        ]);
        $this->requireServerFilter = (bool)($config['require_server_filter'] ?? true);
    }

    public static function fromContext(ApplicationContext $context): self
    {
        $config = function_exists('config')
            ? (array) config($context, 'meilisearch.http_search', [])
            : [];

        if (function_exists('config') && !array_key_exists('allowed_indexes', $config)) {
            $config['allowed_indexes'] = config($context, 'meilisearch.allowed_indexes', []);
        }

        return new self($config);
    }

    /**
     * @param array<string,mixed> $params
     */
    public function prepare(string $index, array $params, ?UserIdentity $user): SearchRequestResult
    {
        if ($this->allowedIndexes === [] || !in_array($index, $this->allowedIndexes, true)) {
            return new SearchRequestResult(false, Response::HTTP_NOT_FOUND, 'Index not found');
        }

        $serverFilter = $this->serverFilters[$index] ?? null;
        $isPublic = in_array($index, $this->publicIndexes, true);
        if ($this->requireServerFilter && !$isPublic && $serverFilter === null) {
            return new SearchRequestResult(
                false,
                Response::HTTP_FORBIDDEN,
                'Search index requires a server-side filter'
            );
        }

        $unknown = array_values(array_diff(array_keys($params), $this->allowedParameters));
        if ($unknown !== []) {
            return new SearchRequestResult(
                false,
                Response::HTTP_BAD_REQUEST,
                'Unsupported search parameter: ' . $unknown[0]
            );
        }

        $prepared = $params;
        $attributes = $this->normalizeStringList($prepared['attributesToRetrieve'] ?? null);
        $allowedAttributes = $this->retrievableAttributes[$index] ?? ['id'];

        if ($attributes === []) {
            $prepared['attributesToRetrieve'] = $allowedAttributes;
        } elseif (!in_array('*', $allowedAttributes, true)) {
            $rejected = array_values(array_diff($attributes, $allowedAttributes));
            if ($rejected !== []) {
                return new SearchRequestResult(
                    false,
                    Response::HTTP_BAD_REQUEST,
                    'Attribute is not retrievable: ' . $rejected[0]
                );
            }
            $prepared['attributesToRetrieve'] = $attributes;
        } else {
            $prepared['attributesToRetrieve'] = $attributes;
        }

        if ($serverFilter !== null) {
            $expanded = $this->expandFilter($serverFilter, $user);
            if ($expanded === null) {
                return new SearchRequestResult(false, Response::HTTP_FORBIDDEN, 'Search scope could not be resolved');
            }
            $prepared['filter'] = $this->mergeFilters($expanded, $prepared['filter'] ?? null);
        }

        return new SearchRequestResult(true, Response::HTTP_OK, 'OK', $prepared);
    }

    private function mergeFilters(string $serverFilter, mixed $callerFilter): string|array
    {
        if ($callerFilter === null || $callerFilter === '' || $callerFilter === []) {
            return $serverFilter;
        }

        if (is_array($callerFilter)) {
            return array_merge([$serverFilter], $callerFilter);
        }

        return '(' . $serverFilter . ') AND (' . (string)$callerFilter . ')';
    }

    private function expandFilter(string $filter, ?UserIdentity $user): ?string
    {
        try {
            return preg_replace_callback('/\{([A-Za-z0-9_.-]+)\}/', function (array $match) use ($user): string {
                $value = $this->resolvePlaceholder($match[1], $user);
                if ($value === null) {
                    throw new \RuntimeException('Missing search scope placeholder');
                }

                return $this->escapeFilterValue((string)$value);
            }, $filter) ?? $filter;
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function resolvePlaceholder(string $key, ?UserIdentity $user): mixed
    {
        if ($user === null) {
            return null;
        }

        return match (true) {
            $key === 'user_uuid', $key === 'user.id', $key === 'user.uuid' => $user->id(),
            str_starts_with($key, 'claim.') => $user->claim(substr($key, 6)),
            str_starts_with($key, 'claims.') => $user->claim(substr($key, 7)),
            default => null,
        };
    }

    private function escapeFilterValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $item): string => trim((string)$item),
            $value
        ), static fn(string $item): bool => $item !== ''));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return $this->normalizeStringList($value);
    }

    /**
     * @return array<string,string>
     */
    private function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_scalar($item) && trim((string)$item) !== '') {
                $result[$key] = trim((string)$item);
            }
        }

        return $result;
    }

    /**
     * @return array<string,list<string>>
     */
    private function stringListMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $this->normalizeStringList($item);
            }
        }

        return $result;
    }
}
