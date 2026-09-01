<?php

namespace Symfony\Lsp\Tests\Feature\Asset;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Asset\Asset;
use Symfony\Lsp\Feature\Asset\AssetCompletionContextResolver;
use Symfony\Lsp\Feature\Asset\AssetExtractor;
use Symfony\Lsp\Feature\Asset\AssetIndexRegistry;
use Symfony\Lsp\Feature\Asset\AssetProvider;
use Symfony\Lsp\Feature\Asset\AssetSourceIndexRegistry;
use Symfony\Lsp\Feature\Asset\ImportMapEntry;
use Symfony\Lsp\Feature\Asset\ImportMapEntrypointExtractor;
use Symfony\Lsp\Feature\Asset\PublicAssetResolver;
use Symfony\Lsp\Feature\Asset\TwigAssetReferenceExtractor;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigArgumentParser;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class AssetProviderTest extends TestCase
{
    public function testProvidesAssetsAndImportmapEntrypoints(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
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
            {## Use asset('documented.svg') and importmap('documented') when needed. #}
            <img src="{{ asset('images/logo.svg') }}">
            {{ importmap(['app', 'missing']) }}
            {{ asset('legacy/logo.svg', 'legacy') }}
            {{ asset('/public/logo.svg') }}
            TWIG;
        $sourceIndexes = new AssetSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace(
            $extractor->extract(new SourceDocument($importMapUri, 'php', $importMapText)),
            $extractor->extract(new SourceDocument($usageUri, 'twig', $usageText)),
        );
        $documents = new DocumentStore();
        $documents->open(new Document($importMapUri, 'php', 1, $importMapText));
        $documents->open(new Document($usageUri, 'twig', 1, $usageText));
        $provider = new AssetProvider(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new UriToPathConverter(),
            new LspProtocolMapper(),
            $indexes,
            $sourceIndexes,
            $extractor,
            new PublicAssetResolver(),
        );

        $assetCompletionUri = 'file:///workspace/templates/asset.html.twig';
        $assetCompletionText = "{{ asset('images/lo";
        $documents->open(new Document($assetCompletionUri, 'twig', 1, $assetCompletionText));
        self::assertSame(['images/logo.svg'], $this->completionLabels($provider, $converter, $assetCompletionUri, $assetCompletionText));

        foreach (["{{ asset(path: 'images/lo", "{{ asset(path = 'images/lo"] as $index => $namedAssetCompletionText) {
            $namedAssetCompletionUri = 'file:///workspace/templates/named-asset-'.$index.'.html.twig';
            $documents->open(new Document($namedAssetCompletionUri, 'twig', 1, $namedAssetCompletionText));
            self::assertSame(['images/logo.svg'], $this->completionLabels($provider, $converter, $namedAssetCompletionUri, $namedAssetCompletionText));
        }

        $entryCompletionUri = 'file:///workspace/templates/entrypoint.html.twig';
        $entryCompletionText = "{{ importmap(['ap";
        $documents->open(new Document($entryCompletionUri, 'twig', 1, $entryCompletionText));
        self::assertSame(['app'], $this->completionLabels($provider, $converter, $entryCompletionUri, $entryCompletionText));

        $commentUri = 'file:///workspace/templates/comment.html.twig';
        $commentText = "{## {{ asset('images/lo') }} #}";
        $documents->open(new Document($commentUri, 'twig', 1, $commentText));
        $commentOffset = strpos($commentText, 'images/lo') + \strlen('images/lo');
        self::assertNull($provider->complete($this->params($converter, $commentUri, $commentText, $commentOffset)));

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

    public function testExtractsStaticTwigAssetArgumentsConservatively(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);

        $facts = $extractor->extract(new SourceDocument('file:///workspace/templates/page.html.twig', 'twig', <<<'TWIG'
            {# {{ asset(path: 'commented.js') }} #}
            {{ asset('positional.js') }}
            {{ asset(path: 'colon.js') }}
            {{ asset(path = "equals.js") }}
            {{ asset(path: # documented
                'comment-separated.js') }}
            {{ asset(path: dynamic_path) }}
            {{ asset(path: 'prefix-' ~ suffix) }}
            {{ asset('packaged.js', 'legacy') }}
            {{ asset(path: 'named-packaged.js', packageName: 'legacy') }}
            {{ asset(path: '/absolute.js') }}
            TWIG));

        self::assertSame(
            ['positional.js', 'colon.js', 'equals.js', 'comment-separated.js'],
            array_map(static fn ($symbol): string => $symbol->name, $facts->symbols),
        );
    }

    public function testDecodesEscapedTwigAssetPaths(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);

        $facts = $extractor->extract(new SourceDocument('file:///workspace/templates/page.html.twig', 'twig', "{{ asset('it\\'s.js') }}"));

        self::assertSame(["it's.js"], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
    }

    /** @return list<string> */
    private function completionLabels(AssetProvider $provider, PositionConverter $converter, string $uri, string $text): array
    {
        $position = $converter->toPosition($text, \strlen($text));
        /** @var list<string> $labels */
        $labels = array_column($provider->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]) ?? [], 'label');

        return $labels;
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        $position = $converter->toPosition($text, $offset);

        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]];
    }

    public function testFallsBackToPublicFilesWithoutAssetMapper(): void
    {
        $root = sys_get_temp_dir().'/lsp-public-assets-'.bin2hex(random_bytes(4));
        mkdir($root.'/public/css', 0o777, true);
        file_put_contents($root.'/public/css/app.css', 'body {}');
        try {
            $converter = new PositionConverter();
            $extractor = $this->createExtractor($converter);
            $rootUri = 'file://'.$root;
            $project = new Project($root, $rootUri, '^8.0');
            $projects = new ProjectRegistry();
            $projects->replace([$project]);
            $uri = $rootUri.'/templates/layout.html.twig';
            $text = "<link href=\"{{ asset('css/app.css') }}\">\n{{ asset('css/missing.css') }}\n";
            $documents = new DocumentStore();
            $documents->open(new Document($uri, 'twig', 1, $text));
            $publicAssets = new PublicAssetResolver();
            $provider = new AssetProvider(
                new DocumentContextResolver($documents, $projects),
                $converter,
                new UriToPathConverter(),
                new LspProtocolMapper(),
                new AssetIndexRegistry(),
                new AssetSourceIndexRegistry(),
                $extractor,
                $publicAssets,
            );

            $params = $this->params($converter, $uri, $text, strpos($text, 'css/app.css') + 2);
            $hover = $provider->hover($params);
            self::assertIsArray($hover);
            self::assertIsArray($hover['contents'] ?? null);
            self::assertIsString($hover['contents']['value'] ?? null);
            self::assertStringContainsString('Public asset', $hover['contents']['value']);
            self::assertSame(['file://'.$root.'/public/css/app.css'], array_column($provider->definition($params) ?? [], 'uri'));
            self::assertSame([], $provider->definition($this->params($converter, $uri, $text, strpos($text, 'css/missing.css') + 2)));

            $completionUri = $rootUri.'/templates/completion.html.twig';
            $completionText = "{{ asset('css/";
            $documents->open(new Document($completionUri, 'twig', 1, $completionText));
            self::assertSame(['css/app.css'], $this->completionLabels($provider, $converter, $completionUri, $completionText));

            unlink($root.'/public/css/app.css');
            file_put_contents($root.'/public/css/admin.css', 'body {}');
            $publicAssets->removeProject($project);
            self::assertSame(['css/admin.css'], $this->completionLabels($provider, $converter, $completionUri, $completionText));
        } finally {
            @unlink($root.'/public/css/app.css');
            @unlink($root.'/public/css/admin.css');
            rmdir($root.'/public/css');
            rmdir($root.'/public');
            rmdir($root);
        }
    }

    private function createExtractor(PositionConverter $converter): AssetExtractor
    {
        $comments = new TwigCommentParser();

        return new AssetExtractor(
            new UriToPathConverter(),
            new TwigAssetReferenceExtractor(
                $converter,
                new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $comments),
                new TwigCallArgumentResolver(new TwigArgumentParser()),
                $comments,
            ),
            new ImportMapEntrypointExtractor($converter, new PhpCommentParser(), new BalancedDelimiterMatcher()),
            new AssetCompletionContextResolver($converter, $comments),
        );
    }
}
