<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Route\Route;
use Symfony\Lsp\Feature\Route\RouteCompletionBuilder;
use Symfony\Lsp\Feature\Route\RouteIndex;
use Symfony\Lsp\Feature\Route\RouteSnapshotImporter;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Runtime\SnapshotSection;
use Symfony\Lsp\Tests\Support\SnapshotSections;

final class RouteCompletionBuilderTest extends TestCase
{
    public function testCompletesRoutesLoadedFromRuntimeSnapshot(): void
    {
        $index = new RouteIndex();
        (new RouteSnapshotImporter($index))->load($this->section([
            'complete' => true,
            'resources' => ['config/routes.yaml', 'config/http_endpoints.yaml'],
            'items' => [
                ['name' => 'admin_user', 'path' => '/admin/user'],
                ['name' => 'article_show', 'path' => '/article/{id}', 'methods' => ['GET']],
                ['name' => 'article_legacy', 'path' => '/article/{id}', 'alias' => 'article_show'],
                ['name' => 'article_edit', 'path' => '/article/{id}/edit'],
            ],
        ]));

        self::assertSame([
            ['label' => 'article_edit', 'kind' => 12, 'detail' => '/article/{id}/edit'],
            ['label' => 'article_show', 'kind' => 12, 'detail' => '/article/{id}'],
        ], (new RouteCompletionBuilder(new LspProtocolMapper()))->complete($index, 'article_'));
        self::assertTrue($index->isResource('config/routes.yaml'));
        self::assertTrue($index->isResource('config/http_endpoints.yaml'));
    }

    public function testCompletesInternationalizedRoutesWithCanonicalNames(): void
    {
        $index = new RouteIndex();
        (new RouteSnapshotImporter($index))->load($this->section([
            'complete' => true,
            'items' => [
                ['name' => 'app_home.en', 'canonical' => 'app_home', 'path' => '/en/{english}'],
                ['name' => 'app_home.fr', 'canonical' => 'app_home', 'path' => '/fr/{french}'],
                ['name' => 'legacy_home.en', 'canonical' => 'legacy_home', 'path' => '/en/{english}', 'alias' => 'app_home.en'],
                ['name' => 'legacy_home.fr', 'canonical' => 'legacy_home', 'path' => '/fr/{french}', 'alias' => 'app_home.fr'],
            ],
        ]));

        self::assertSame([
            ['label' => 'app_home', 'kind' => 12, 'detail' => 'Symfony route'],
        ], (new RouteCompletionBuilder(new LspProtocolMapper()))->complete($index, ''));
        self::assertInstanceOf(Route::class, $index->get('legacy_home'));
    }

    public function testLoadsRouterRequestContextParameters(): void
    {
        $index = new RouteIndex();
        (new RouteSnapshotImporter($index))->load($this->section([
            'complete' => true,
            'contextParameters' => ['_locale', null],
            'items' => [
                ['name' => 'localized_article', 'path' => '/{_locale}/article/{id}'],
            ],
        ]));

        $route = $index->get('localized_article');
        self::assertInstanceOf(Route::class, $route);
        self::assertSame(['id'], $index->missingParameters($route, []));
    }

    public function testIgnoresMalformedSnapshotEntries(): void
    {
        $index = new RouteIndex();
        (new RouteSnapshotImporter($index))->load($this->section([
            'complete' => true,
            'resources' => [null, 'config/routes.yaml'],
            'items' => [null, ['path' => '/']],
        ]));

        self::assertSame([], (new RouteCompletionBuilder(new LspProtocolMapper()))->complete($index, ''));
        self::assertTrue($index->isResource('config/routes.yaml'));
    }

    public function testReplacesRouteResourcesFromCompleteSnapshots(): void
    {
        $index = new RouteIndex();
        $index->replaceRuntime(['config/old_routes.yaml'], []);
        (new RouteSnapshotImporter($index))->load($this->section([
            'complete' => true,
            'resources' => ['config/new_routes.yaml'],
            'items' => [],
        ]));

        self::assertFalse($index->isResource('config/old_routes.yaml'));
        self::assertTrue($index->isResource('config/new_routes.yaml'));
    }

    /** @param array<array-key, mixed> $values */
    private function section(array $values): SnapshotSection
    {
        return SnapshotSections::of(new Project('/workspace', 'file:///workspace'), $values);
    }
}
