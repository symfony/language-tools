<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Route\RouteCompletionProvider;
use Symfony\Lsp\Feature\Route\RouteIndex;
use Symfony\Lsp\Feature\Route\RouteSnapshotLoader;

final class RouteCompletionProviderTest extends TestCase
{
    public function testCompletesRoutesLoadedFromRuntimeSnapshot(): void
    {
        $index = new RouteIndex();
        (new RouteSnapshotLoader($index))->load([
            'sections' => [
                'routes' => [
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
        ], (new RouteCompletionProvider($index))->complete('article_'));
    }

    public function testIgnoresMalformedSnapshotEntries(): void
    {
        $index = new RouteIndex();
        (new RouteSnapshotLoader($index))->load([
            'sections' => ['routes' => ['items' => [null, ['path' => '/']]]],
        ]);

        self::assertSame([], (new RouteCompletionProvider($index))->complete(''));
    }
}
