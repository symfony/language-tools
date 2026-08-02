<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Twig\ProjectTemplateSnapshotLoader;
use Symfony\Lsp\Feature\Twig\TemplateCodeActionProvider;
use Symfony\Lsp\Feature\Twig\TemplateCompletionHandler;
use Symfony\Lsp\Feature\Twig\TemplateDeclaration;
use Symfony\Lsp\Feature\Twig\TemplateIndexRegistry;
use Symfony\Lsp\Feature\Twig\TemplateNavigationProvider;
use Symfony\Lsp\Feature\Twig\TemplateReference;
use Symfony\Lsp\Feature\Twig\TemplateReferenceExtractor;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class TemplateProviderTest extends TestCase
{
    public function testExtractsValidReferencesAroundMalformedTwigWithoutMatchingComments(): void
    {
        $extractor = new TemplateReferenceExtractor(new PositionConverter(), new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())));
        $references = $extractor->extract('file:///workspace/templates/page.html.twig', 'twig', <<<'TWIG'
            {# {% include 'ignored.html.twig' %} #}
            {{ include(template_name('ignored.html.twig')) }}
            {% extends 'base.html.twig' %}
            {{ include('card.html.twig') }}
            {{ include('unfinished.html.twig'
            TWIG);

        self::assertSame(
            ['base.html.twig', 'card.html.twig'],
            array_map(static fn (TemplateReference $reference): string => $reference->name(), $references),
        );
    }

    public function testIndexesTemplatesWithoutATwigExtension(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($root.'/templates', 0777, true);
        file_put_contents($root.'/templates/index.html', 'Hello');
        $project = new Project($root, 'file://'.$root, '^8.0');
        $indexes = new TemplateIndexRegistry();

        try {
            (new ProjectTemplateSnapshotLoader($indexes))->load($project, ['sections' => ['twig' => [
                'complete' => true,
                'paths' => [['namespace' => '(None)', 'path' => $root.'/templates']],
            ]]]);

            self::assertSame('file://'.$root.'/templates/index.html', $indexes->forProject($project)->get('index.html')?->uri());
        } finally {
            @unlink($root.'/templates/index.html');
            @rmdir($root.'/templates');
            @rmdir($root);
        }
    }

    public function testCreatesMissingApplicationTemplate(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = "<?php \$this->render('missing.html.twig');";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new TemplateIndexRegistry();
        $indexes->forProject($project)->replaceRuntime(true);
        $converter = new PositionConverter();
        $extractor = new TemplateReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())));
        $navigation = new TemplateNavigationProvider(new DocumentContextResolver($documents, $projects), $documents, $projects, $converter, $extractor, $indexes);
        $diagnostics = $navigation->diagnostics(['textDocument' => ['uri' => $uri]]);
        self::assertIsArray($diagnostics);
        $provider = new TemplateCodeActionProvider($documents, $projects, $extractor, $indexes);

        $actions = $provider->actions([
            'textDocument' => ['uri' => $uri],
            'range' => $diagnostics[0]['range'],
            'context' => ['diagnostics' => $diagnostics],
        ]);

        self::assertIsArray($actions);
        self::assertCount(1, $actions);
        $action = $actions[0];
        self::assertSame('Create template "missing.html.twig"', $action['title'] ?? null);
        self::assertIsArray($action['edit'] ?? null);
        self::assertIsArray($action['edit']['documentChanges'] ?? null);
        self::assertIsArray($action['edit']['documentChanges'][0]);
        self::assertSame('file:///workspace/templates/missing.html.twig', $action['edit']['documentChanges'][0]['uri'] ?? null);
    }

    public function testCompletesAndLinksTemplateNames(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = "<?php \$this->render('article/show.html.twig');";
        [$completion, $navigation, $converter] = $this->providers($uri, 'php', $text);
        $position = $converter->toPosition($text, strpos($text, 'article/sh') + \strlen('article/sh'));
        $params = ['textDocument' => ['uri' => $uri], 'position' => [
            'line' => $position->line(), 'character' => $position->character(),
        ]];

        self::assertSame(['article/show.html.twig'], array_column($completion->complete($params) ?? [], 'label'));
        self::assertSame(
            'file:///workspace/templates/article/show.html.twig',
            $navigation->links(['textDocument' => ['uri' => $uri]])[0]['target'] ?? null,
        );
    }

    public function testNavigatesReferencesAndDiagnosesMissingTemplates(): void
    {
        $uri = 'file:///workspace/templates/page.html.twig';
        $text = "{% extends 'article/show.html.twig' %}\n{% include 'missing.html.twig' %}";
        [, $navigation, $converter] = $this->providers($uri, 'twig', $text);
        $position = $converter->toPosition($text, strpos($text, 'article/show') + 1);
        $params = ['textDocument' => ['uri' => $uri], 'position' => [
            'line' => $position->line(), 'character' => $position->character(),
        ]];

        self::assertSame(
            ['file:///workspace/templates/article/show.html.twig'],
            array_column($navigation->definition($params) ?? [], 'uri'),
        );
        self::assertSame(
            ['template.not_found'],
            array_column($navigation->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'),
        );
    }

    /** @return array{TemplateCompletionHandler, TemplateNavigationProvider, PositionConverter} */
    private function providers(string $uri, string $languageId, string $text): array
    {
        $documents = new DocumentStore();
        $documents->open(new Document($uri, $languageId, 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new TemplateIndexRegistry();
        $indexes->forProject($project)->replaceRuntime(true, new TemplateDeclaration(
            'article/show.html.twig',
            'file:///workspace/templates/article/show.html.twig',
            new Range(new Position(0, 0), new Position(0, 0)),
        ));
        $converter = new PositionConverter();
        $extractor = new TemplateReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())));
        $resolver = new DocumentContextResolver($documents, $projects);

        return [
            new TemplateCompletionHandler($resolver, $converter, $indexes),
            new TemplateNavigationProvider($resolver, $documents, $projects, $converter, $extractor, $indexes),
            $converter,
        ];
    }
}
