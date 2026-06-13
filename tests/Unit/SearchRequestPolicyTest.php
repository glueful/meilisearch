<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Tests\Unit;

use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Meilisearch\Security\SearchRequestPolicy;
use PHPUnit\Framework\TestCase;

final class SearchRequestPolicyTest extends TestCase
{
    public function testItDeniesIndexesOutsideTheExplicitAllowlist(): void
    {
        $policy = new SearchRequestPolicy([
            'allowed_indexes' => ['posts'],
            'public_indexes' => ['posts'],
        ]);

        $result = $policy->prepare('users', [], new UserIdentity('user-1'));

        self::assertFalse($result->allowed);
        self::assertSame(404, $result->status);
    }

    public function testItRequiresPrivateIndexesToHaveAServerSideFilter(): void
    {
        $policy = new SearchRequestPolicy([
            'allowed_indexes' => ['posts'],
            'public_indexes' => [],
            'require_server_filter' => true,
        ]);

        $result = $policy->prepare('posts', [], new UserIdentity('user-1'));

        self::assertFalse($result->allowed);
        self::assertSame(403, $result->status);
    }

    public function testItMergesServerFilterWithCallerFilterAndRestrictsRetrievableAttributes(): void
    {
        $policy = new SearchRequestPolicy([
            'allowed_indexes' => ['posts'],
            'server_filters' => [
                'posts' => 'tenant_uuid = "{claims.tenant_uuid}"',
            ],
            'retrievable_attributes' => [
                'posts' => ['id', 'title'],
            ],
        ]);
        $user = new UserIdentity('user-1', attributes: ['tenant_uuid' => 'tenant-1']);

        $result = $policy->prepare('posts', [
            'filter' => 'status = "published"',
            'attributesToRetrieve' => ['id', 'secret'],
            'limit' => 10,
        ], $user);

        self::assertFalse($result->allowed);
        self::assertSame(400, $result->status);

        $result = $policy->prepare('posts', [
            'filter' => 'status = "published"',
            'attributesToRetrieve' => ['id', 'title'],
            'limit' => 10,
        ], $user);

        self::assertTrue($result->allowed);
        self::assertSame('(tenant_uuid = "tenant-1") AND (status = "published")', $result->params['filter']);
        self::assertSame(['id', 'title'], $result->params['attributesToRetrieve']);
        self::assertSame(10, $result->params['limit']);
    }

    public function testItDefaultsHttpRetrievalToIdOnly(): void
    {
        $policy = new SearchRequestPolicy([
            'allowed_indexes' => ['posts'],
            'public_indexes' => ['posts'],
        ]);

        $result = $policy->prepare('posts', [], new UserIdentity('user-1'));

        self::assertTrue($result->allowed);
        self::assertSame(['id'], $result->params['attributesToRetrieve']);
    }
}
