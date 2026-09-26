<?php

namespace Symfony\Lsp\Tests\Feature\Asset;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Asset\AssetExtractor;
use Symfony\Lsp\Feature\Asset\AssetProvider;
use Symfony\Lsp\Feature\Asset\PublicAssetResolver;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class AssetProviderTest extends TestCase
{
    public function testProvidesAssetsAndImportmapEntrypoints(): void
    {
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
        $kit = (new ProjectTestKit())
            ->open($importMapUri, $importMapText)
            ->open($usageUri, $usageText)
            ->index()
            ->runtime('assets', [
                'assetsComplete' => true,
                'importMapComplete' => true,
                'assets' => [['logicalPath' => 'images/logo.svg', 'sourcePath' => '/workspace/assets/images/logo.svg', 'vendor' => false]],
                'importMap' => [
                    ['name' => 'app', 'path' => './assets/app.js', 'entrypoint' => true, 'version' => null],
                    ['name' => 'stimulus', 'path' => '@hotwired/stimulus', 'entrypoint' => false, 'version' => '3.2.2'],
                ],
            ])
        ;
        $provider = $kit->get(AssetProvider::class);

        self::assertSame(['images/logo.svg'], $this->completeAtEnd($kit, 'file:///workspace/templates/asset.html.twig', "{{ asset('images/lo"));

        foreach (["{{ asset(path: 'images/lo", "{{ asset(path = 'images/lo"] as $index => $namedAssetCompletionText) {
            self::assertSame(['images/logo.svg'], $this->completeAtEnd($kit, 'file:///workspace/templates/named-asset-'.$index.'.html.twig', $namedAssetCompletionText));
        }

        self::assertSame(['app'], $this->completeAtEnd($kit, 'file:///workspace/templates/entrypoint.html.twig', "{{ importmap(['ap"));

        $commentUri = 'file:///workspace/templates/comment.html.twig';
        $kit->open($commentUri, "{## {{ asset('images/lo') }} #}");
        self::assertSame([], $provider->complete($kit->positioned($kit->after($commentUri, 'images/lo'))));

        $markupUri = 'file:///workspace/templates/markup.html.twig';
        $markupText = "<p>Call asset('images/lo";
        $kit->open($markupUri, $markupText);
        self::assertSame([], $provider->complete($kit->positioned($kit->offset($markupUri, \strlen($markupText)))));

        $assetParams = $kit->offset($usageUri, strpos($usageText, 'images/logo.svg') + 2);
        self::assertStringContainsString('AssetMapper asset', $kit->hoverText($provider->hover($kit->positioned($assetParams))));
        self::assertSame(['file:///workspace/assets/images/logo.svg'], $kit->targets($provider->definition($kit->positioned($assetParams))));
        self::assertCount(1, $provider->references($kit->references($assetParams)));

        $entryParams = $kit->offset($usageUri, strpos($usageText, "'app'") + 2);
        self::assertSame([$importMapUri], $kit->targets($provider->definition($kit->positioned($entryParams))));
        self::assertCount(2, $provider->references($kit->references($entryParams)));
        self::assertCount(2, $provider->links($kit->document($usageUri)));
        self::assertSame(['importmap.unknown_entrypoint'], $kit->codes($provider->diagnostics($kit->document($usageUri))));
    }

    public function testExtractsStaticTwigAssetArgumentsConservatively(): void
    {
        $extractor = $this->extractor();

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

    public function testExtractsStaticTwigImportmapEntrypointsConservatively(): void
    {
        $extractor = $this->extractor();

        $facts = $extractor->extract(new SourceDocument('file:///workspace/templates/page.html.twig', 'twig', <<<'TWIG'
            {# {{ importmap('commented') }} #}
            {{ importmap('single') }}
            {{ importmap(['listed', 'other']) }}
            {{ importmap('attributed', {defer: true}) }}
            {{ importmap(['static', dynamic]) }}
            {{ importmap(entryPoint: 'named') }}
            {{ importmap(entrypoint) }}
            {{ importmap() }}
            {{ app.importmap('method') }}
            {% set snippet = "importmap('string')" %}
            {% verbatim %}{{ importmap('verbatim') }}{% endverbatim %}
            TWIG));

        self::assertSame(
            ['single', 'listed', 'other', 'attributed', 'static', 'named'],
            array_map(static fn ($symbol): string => $symbol->name, $facts->symbols),
        );
    }

    public function testDecodesEscapedTwigAssetPaths(): void
    {
        $extractor = $this->extractor();

        $facts = $extractor->extract(new SourceDocument('file:///workspace/templates/page.html.twig', 'twig', "{{ asset('it\\'s.js') }}"));

        self::assertSame(["it's.js"], array_map(static fn ($symbol): string => $symbol->name, $facts->symbols));
    }

    public function testFallsBackToPublicFilesWithoutAssetMapper(): void
    {
        $root = sys_get_temp_dir().'/lsp-public-assets-'.bin2hex(random_bytes(4));
        mkdir($root.'/public/css', 0o777, true);
        file_put_contents($root.'/public/css/app.css', 'body {}');
        try {
            $rootUri = 'file://'.$root;
            $uri = $rootUri.'/templates/layout.html.twig';
            $text = "<link href=\"{{ asset('css/app.css') }}\">\n{{ asset('css/missing.css') }}\n";
            $kit = (new ProjectTestKit($root))->open($uri, $text);
            $provider = $kit->get(AssetProvider::class);

            $params = $kit->offset($uri, strpos($text, 'css/app.css') + 2);
            self::assertStringContainsString('Public asset', $kit->hoverText($provider->hover($kit->positioned($params))));
            self::assertSame(['file://'.$root.'/public/css/app.css'], $kit->targets($provider->definition($kit->positioned($params))));
            self::assertSame([], $provider->definition($kit->positioned($kit->offset($uri, strpos($text, 'css/missing.css') + 2))));

            $completionUri = $rootUri.'/templates/completion.html.twig';
            $completionText = "{{ asset('css/";
            self::assertSame(['css/app.css'], $this->completeAtEnd($kit, $completionUri, $completionText));

            unlink($root.'/public/css/app.css');
            file_put_contents($root.'/public/css/admin.css', 'body {}');
            $kit->get(PublicAssetResolver::class)->removeProject($kit->project());
            self::assertSame(['css/admin.css'], $this->completeAtEnd($kit, $completionUri, $completionText));
        } finally {
            @unlink($root.'/public/css/app.css');
            @unlink($root.'/public/css/admin.css');
            rmdir($root.'/public/css');
            rmdir($root.'/public');
            rmdir($root);
        }
    }

    /** @return list<mixed> */
    private function completeAtEnd(ProjectTestKit $kit, string $uri, string $text): array
    {
        $kit->open($uri, $text);

        return $kit->labels($kit->get(AssetProvider::class)->complete($kit->positioned($kit->offset($uri, \strlen($text)))));
    }

    private function extractor(): AssetExtractor
    {
        return (new ProjectTestKit())->get(AssetExtractor::class);
    }
}
