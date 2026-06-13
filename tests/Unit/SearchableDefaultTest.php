<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Tests\Unit;

use Glueful\Extensions\Meilisearch\Model\Searchable;
use PHPUnit\Framework\TestCase;

final class SearchableDefaultTest extends TestCase
{
    public function testDefaultSearchablePayloadIndexesOnlyThePrimaryIdentifier(): void
    {
        $model = new class {
            use Searchable;

            public string $uuid = 'post-1';
            public string $title = 'Visible';
            public string $secret = 'do-not-index';

            public function toArray(): array
            {
                return [
                    'uuid' => $this->uuid,
                    'title' => $this->title,
                    'secret' => $this->secret,
                ];
            }
        };

        self::assertSame(['id' => 'post-1'], $model->toSearchableArray());
    }
}
