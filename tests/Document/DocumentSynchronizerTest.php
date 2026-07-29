<?php

namespace Symfony\Lsp\Tests\Document;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\DocumentSynchronizer;
use Symfony\Lsp\Document\PositionConverter;

final class DocumentSynchronizerTest extends TestCase
{
    public function testSynchronizesOpenIncrementalChangeAndClose(): void
    {
        $store = new DocumentStore();
        $synchronizer = new DocumentSynchronizer($store, new PositionConverter());
        $uri = 'file:///workspace/src/Controller.php';

        $synchronizer->open(['textDocument' => [
            'uri' => $uri,
            'languageId' => 'php',
            'version' => 1,
            'text' => 'a😀b',
        ]]);
        $synchronizer->change([
            'textDocument' => ['uri' => $uri, 'version' => 2],
            'contentChanges' => [[
                'range' => [
                    'start' => ['line' => 0, 'character' => 1],
                    'end' => ['line' => 0, 'character' => 3],
                ],
                'text' => 'route',
            ]],
        ]);

        self::assertSame(2, $store->get($uri)?->version());
        self::assertSame('arouteb', $store->get($uri)->text());

        $synchronizer->close(['textDocument' => ['uri' => $uri]]);

        self::assertNull($store->get($uri));
    }

    public function testAppliesFullDocumentChanges(): void
    {
        $store = new DocumentStore();
        $synchronizer = new DocumentSynchronizer($store, new PositionConverter());
        $uri = 'file:///workspace/config/routes.yaml';
        $synchronizer->open(['textDocument' => [
            'uri' => $uri,
            'languageId' => 'yaml',
            'version' => 1,
            'text' => 'old',
        ]]);

        $synchronizer->change([
            'textDocument' => ['uri' => $uri, 'version' => 2],
            'contentChanges' => [['text' => 'new']],
        ]);

        self::assertSame('new', $store->get($uri)?->text());
    }
}
