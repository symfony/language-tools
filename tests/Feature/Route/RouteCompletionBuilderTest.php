<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Route\RouteCompletionBuilder;
use Symfony\Lsp\Feature\Route\RouteIndex;
use Symfony\Lsp\Feature\Route\RouteSnapshotLoader;

final class RouteCompletionBuilderTest extends TestCase
{
    public function testCompletesRoutesLoadedFromRuntimeSnapshot(): void
    {
        $index = new RouteIndex();
        (new RouteSnapshotLoader($index))->load([
            'sections' => [
                'routes' => [
                    'complete' => true,
                    'resources' => ['config/routes.yaml', 'config/http_endpoints.yaml'],
                    'items' => [
                        ['name' => 'admin_user', 'path' => '/admin/user'],
                        ['name' => 'article_show', 'path' => '/article/{id}', 'methods' => ['GET']],
                        ['name' => 'article_edit', 'path' => '/article/{id}/edit'],
                    ],
                ],
            ],
        ]);

        self::assertSame([
            ['label' => 'article_edit', 'kind' => 12, 'detail' => '/article/{id}/edit'],
            ['label' => 'article_show', 'kind' => 12, 'detail' => '/article/{id}'],
        ], (new RouteCompletionBuilder())->complete($index, 'article_'));
        self::assertTrue($index->isResource('config/routes.yaml'));
        self::assertTrue($index->isResource('config/http_endpoints.yaml'));
    }

    public function testCompletesInternationalizedRoutesWithCanonicalNames(): void
    {
        $index = new RouteIndex();
        (new RouteSnapshotLoader($index))->load([
            'sections' => [
                'routes' => [
                    'complete' => true,
                    'items' => [
                        ['name' => 'app_home.en', 'canonical' => 'app_home', 'path' => '/en/{english}'],
                        ['name' => 'app_home.fr', 'canonical' => 'app_home', 'path' => '/fr/{french}'],
                    ],
                ],
            ],
        ]);

        self::assertSame([
            ['label' => 'app_home', 'kind' => 12, 'detail' => 'Symfony route'],
        ], (new RouteCompletionBuilder())->complete($index, 'app_'));
    }

    public function testIgnoresMalformedSnapshotEntries(): void
    {
        $index = new RouteIndex();
        (new RouteSnapshotLoader($index))->load([
            'sections' => ['routes' => [
                'complete' => true,
                'resources' => [null, 'config/routes.yaml'],
                'items' => [null, ['path' => '/']],
            ]],
        ]);

        self::assertSame([], (new RouteCompletionBuilder())->complete($index, ''));
        self::assertTrue($index->isResource('config/routes.yaml'));
    }

    public function testReplacesRouteResourcesFromCompleteSnapshots(): void
    {
        $index = new RouteIndex();
        $index->replaceRuntime(['config/old_routes.yaml']);
        (new RouteSnapshotLoader($index))->load([
            'sections' => ['routes' => [
                'complete' => true,
                'resources' => ['config/new_routes.yaml'],
                'items' => [],
            ]],
        ]);

        self::assertFalse($index->isResource('config/old_routes.yaml'));
        self::assertTrue($index->isResource('config/new_routes.yaml'));
    }
}
