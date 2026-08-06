<?php

namespace Symfony\Lsp\Tests\Feature\Asset;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Asset\Asset;
use Symfony\Lsp\Feature\Asset\AssetExtractor;
use Symfony\Lsp\Feature\Asset\AssetIndexRegistry;
use Symfony\Lsp\Feature\Asset\AssetProvider;
use Symfony\Lsp\Feature\Asset\AssetSourceIndexRegistry;
use Symfony\Lsp\Feature\Asset\ImportMapEntry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class AssetProviderTest extends TestCase
{
    public function testProvidesAssetsAndImportmapEntrypoints(): void
    {
        $converter = new PositionConverter();
        $extractor = new AssetExtractor($converter, new UriToPathConverter());
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = new AssetIndexRegistry();
        $indexes->forProject($project)->replace(
            [new Asset('images/logo.svg', '/workspace/assets/images/logo.svg', false)],
            [
                new ImportMapEntry('app', './assets/app.js', true, null),
                new ImportMapEntry('stimulus', '@hotwired/stimulus', false, '3.2.2'),
            ],
            true,
            true,
        );
        $importMapUri = 'file:///workspace/importmap.php';
        $importMapText = <<<'PHP'
            <?php
            return [
                'app' => [
                    'path' => './assets/app.js',
                    'entrypoint' => true,
                ],
                'stimulus' => [
                    'version' => '3.2.2',
                ],
            ];
            PHP;
        $usageUri = 'file:///workspace/templates/layout.html.twig';
        $usageText = <<<'TWIG'
            <img src="{{ asset('images/logo.svg') }}">
            {{ importmap(['app', 'missing']) }}
            {{ asset('legacy/logo.svg', 'legacy') }}
            {{ asset('/public/logo.svg') }}
            TWIG;
        $sourceIndexes = new AssetSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace(
            $extractor->extract($importMapUri, 'php', $importMapText),
            $extractor->extract($usageUri, 'twig', $usageText),
        );
        $documents = new DocumentStore();
        $documents->open(new Document($importMapUri, 'php', 1, $importMapText));
        $documents->open(new Document($usageUri, 'twig', 1, $usageText));
        $provider = new AssetProvider(
            new DocumentContextResolver($documents, $projects),
            $documents,
            $projects,
            $converter,
            new UriToPathConverter(),
            $indexes,
            $sourceIndexes,
            $extractor,
        );

        $assetCompletionUri = 'file:///workspace/templates/asset.html.twig';
        $assetCompletionText = "{{ asset('images/lo";
        $documents->open(new Document($assetCompletionUri, 'twig', 1, $assetCompletionText));
        self::assertSame(['images/logo.svg'], $this->completionLabels($provider, $converter, $assetCompletionUri, $assetCompletionText));

        $entryCompletionUri = 'file:///workspace/templates/entrypoint.html.twig';
        $entryCompletionText = "{{ importmap(['ap";
        $documents->open(new Document($entryCompletionUri, 'twig', 1, $entryCompletionText));
        self::assertSame(['app'], $this->completionLabels($provider, $converter, $entryCompletionUri, $entryCompletionText));

        $assetOffset = strpos($usageText, 'images/logo.svg') + 2;
        $assetParams = $this->params($converter, $usageUri, $usageText, $assetOffset);
        $assetHover = $provider->hover($assetParams);
        self::assertIsArray($assetHover);
        self::assertIsArray($assetHover['contents'] ?? null);
        self::assertIsString($assetHover['contents']['value'] ?? null);
        self::assertStringContainsString('AssetMapper asset', $assetHover['contents']['value']);
        self::assertSame(['file:///workspace/assets/images/logo.svg'], array_column($provider->definition($assetParams) ?? [], 'uri'));
        self::assertCount(1, $provider->references($assetParams) ?? []);

        $entryOffset = strpos($usageText, "'app'") + 2;
        $entryParams = $this->params($converter, $usageUri, $usageText, $entryOffset);
        self::assertSame([$importMapUri], array_column($provider->definition($entryParams) ?? [], 'uri'));
        self::assertCount(2, $provider->references($entryParams) ?? []);
        self::assertCount(2, $provider->links(['textDocument' => ['uri' => $usageUri]]) ?? []);
        $diagnostics = $provider->diagnostics(['textDocument' => ['uri' => $usageUri]]);
        self::assertIsArray($diagnostics);
        self::assertSame(['importmap.unknown_entrypoint'], array_column($diagnostics, 'code'));
    }

    /** @return list<string> */
    private function completionLabels(AssetProvider $provider, PositionConverter $converter, string $uri, string $text): array
    {
        $position = $converter->toPosition($text, \strlen($text));
        /** @var list<string> $labels */
        $labels = array_column($provider->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ]) ?? [], 'label');

        return $labels;
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        $position = $converter->toPosition($text, $offset);

        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]];
    }
}
