<?php

declare(strict_types=1);

namespace Glueful\Extensions\Meilisearch\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

final class SchemaManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);
        return $composer['extra']['glueful'];
    }

    public function testDeclaresSchemaFreeManifest(): void
    {
        self::assertSame('none', $this->manifest()['migrations'], 'an empty schema declares "none" explicitly');
        self::assertSame('>=1.79.0', $this->manifest()['requires']['glueful']);
        self::assertSame([], $this->manifest()['requires']['extensions']);
    }

    public function testProjectsAsDeclaredWithZeroDescriptors(): void
    {
        $base = sys_get_temp_dir() . '/meilisearch-mf-' . uniqid('', true);
        mkdir($base . '/vendor/composer', 0777, true);
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);
        $composer['install-path'] = dirname(__DIR__, 3);
        file_put_contents(
            $base . '/vendor/composer/installed.json',
            json_encode(['packages' => [$composer]])
        );
        $manifest = new \Glueful\Extensions\PackageManifest(new \Glueful\Bootstrap\ApplicationContext($base));

        self::assertSame([], $manifest->migrationDescriptors()['glueful/meilisearch']);
        self::assertNotContains('glueful/meilisearch', $manifest->undeclaredGluefulPackages());
    }

    public function testResolvesWhenEnabledAloneAtItsDeclaredFloor(): void
    {
        $g = $this->manifest();
        $candidates = ['glueful/meilisearch' => new \Glueful\Extensions\ExtensionCandidate(
            name: 'glueful/meilisearch',
            provider: $g['provider'],
            requiresGlueful: $g['requires']['glueful'],
            requiresExtensions: $g['requires']['extensions'],
        )];
        $result = (new \Glueful\Extensions\ExtensionResolver())
            ->resolve($candidates, [$g['provider']], '1.79.0');
        self::assertSame([], $result->errors);
    }
}
