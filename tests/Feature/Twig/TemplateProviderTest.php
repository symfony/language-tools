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
use Symfony\Lsp\Feature\Twig\TemplateNameResolver;
use Symfony\Lsp\Feature\Twig\TemplateNavigationProvider;
use Symfony\Lsp\Feature\Twig\TemplateReference;
use Symfony\Lsp\Feature\Twig\TemplateReferenceExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponent;
use Symfony\Lsp\Feature\Twig\TwigComponentExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponentIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigComponentProvider;
use Symfony\Lsp\Feature\Twig\TwigVariableProvider;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

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

    public function testCompletesRenderContextVariablesAndTwigGlobals(): void
    {
        $converter = new PositionConverter();
        $extractor = new TemplateReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())));
        $reference = $extractor->extract(
            'file:///workspace/src/Controller.php',
            'php',
            "<?php \$this->render('article/show.html.twig', ['article' => \$article, 'can_edit' => true]);",
        )[0];
        self::assertSame(['article', 'can_edit'], $reference->variables());

        $uri = 'file:///workspace/templates/article/show.html.twig';
        $text = '{{ art }} {{ app }}';
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'twig', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new TemplateIndexRegistry();
        $indexes->forProject($project)->replaceReferences($reference);
        $indexes->forProject($project)->replaceGlobals(['app']);
        $provider = new TwigVariableProvider(new DocumentContextResolver($documents, $projects), $converter, $indexes, new TwigComponentIndexRegistry(), $this->templateNameResolver());
        $position = $converter->toPosition($text, strpos($text, 'art') + 3);

        self::assertSame(['article'], array_column($provider->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ]) ?? [], 'label'));
        $hoverPosition = $converter->toPosition($text, strrpos($text, 'app') + 1);
        $hover = $provider->hover([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $hoverPosition->line(), 'character' => $hoverPosition->character()],
        ]);
        self::assertIsArray($hover);
        self::assertIsArray($hover['contents'] ?? null);
        self::assertIsString($hover['contents']['value'] ?? null);
        self::assertStringContainsString('Twig global', $hover['contents']['value']);
    }

    public function testIndexesAndProvidesTwigComponents(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $converter = new PositionConverter();
        $extractor = new TwigComponentExtractor($converter, $this->templateNameResolver());
        $classUri = 'file:///workspace/src/Twig/Alert.php';
        $classText = <<<'PHP'
            <?php
            namespace App\Twig;
            #[AsTwigComponent(name: 'Alert', template: 'components/Alert.html.twig')]
            final class Alert
            {
                public string $title;
            }
            PHP;
        $templateUri = 'file:///workspace/templates/components/Alert.html.twig';
        $usageUri = 'file:///workspace/templates/page.html.twig';
        $usageText = '<twig:Alert title="Hello" />';
        $indexes = new TwigComponentIndexRegistry();
        $indexes->forProject($project)->replace(
            $extractor->extract($project, $classUri, 'php', $classText),
            $extractor->extract($project, $templateUri, 'twig', '{{ title }}'),
            $extractor->extract($project, $usageUri, 'twig', $usageText),
        );
        $component = $indexes->forProject($project)->get('Alert');
        self::assertInstanceOf(TwigComponent::class, $component);
        self::assertSame('App\\Twig\\Alert', $component->className());
        self::assertSame(['title'], $component->properties());

        $documents = new DocumentStore();
        $completionUri = 'file:///workspace/templates/completion.html.twig';
        $completionText = '<twig:Al';
        $documents->open(new Document($completionUri, 'twig', 1, $completionText));
        $documents->open(new Document($usageUri, 'twig', 1, $usageText));
        $componentTemplateText = '{{ ti }}';
        $documents->open(new Document($templateUri, 'twig', 1, $componentTemplateText));
        $documents->open(new Document($classUri, 'php', 1, $classText));
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $provider = new TwigComponentProvider($documents, $projects, $converter, $indexes, $extractor);
        $completionPosition = $converter->toPosition($completionText, \strlen($completionText));
        self::assertSame(['Alert'], array_column($provider->complete([
            'textDocument' => ['uri' => $completionUri],
            'position' => ['line' => $completionPosition->line(), 'character' => $completionPosition->character()],
        ]) ?? [], 'label'));

        $usagePosition = $converter->toPosition($usageText, strpos($usageText, 'Alert') + 1);
        $params = [
            'textDocument' => ['uri' => $usageUri],
            'position' => ['line' => $usagePosition->line(), 'character' => $usagePosition->character()],
        ];
        self::assertSame([$classUri, $templateUri], array_column($provider->definition($params) ?? [], 'uri'));
        self::assertCount(1, $provider->references($params) ?? []);
        $hover = $provider->hover($params);
        self::assertIsArray($hover);
        self::assertIsArray($hover['contents'] ?? null);
        self::assertIsString($hover['contents']['value'] ?? null);
        self::assertStringContainsString('Properties: `title`', $hover['contents']['value']);
        self::assertSame([], $provider->diagnostics(['textDocument' => ['uri' => $usageUri]]));
        $lenses = $provider->codeLenses(['textDocument' => ['uri' => $classUri]]);
        self::assertIsArray($lenses);
        self::assertCount(1, $lenses);
        self::assertIsArray($lenses[0]['command'] ?? null);
        self::assertSame('1 Twig component usage', $lenses[0]['command']['title'] ?? null);
        $variableProvider = new TwigVariableProvider(new DocumentContextResolver($documents, $projects), $converter, new TemplateIndexRegistry(), $indexes, $this->templateNameResolver());
        $variablePosition = $converter->toPosition($componentTemplateText, strpos($componentTemplateText, 'ti') + 2);
        self::assertSame(['title'], array_column($variableProvider->complete([
            'textDocument' => ['uri' => $templateUri],
            'position' => ['line' => $variablePosition->line(), 'character' => $variablePosition->character()],
        ]) ?? [], 'label'));
    }

    public function testResolvesBundleTemplateNames(): void
    {
        $resolver = $this->templateNameResolver();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');

        self::assertSame('@AcmeBundle/article/show.html.twig', $resolver->resolve($project, 'file:///workspace/templates/bundles/AcmeBundle/article/show.html.twig'));
        self::assertSame('bundles/AcmeBundle/article/show.html.twig', $resolver->relative($project, 'file:///workspace/templates/bundles/AcmeBundle/article/show.html.twig'));
        self::assertNull($resolver->resolve($project, 'file:///workspace/src/article/show.html.twig'));
    }

    private function templateNameResolver(): TemplateNameResolver
    {
        return new TemplateNameResolver(new ProjectPathResolver(new UriToPathConverter()));
    }

    public function testIndexesTemplatesWithoutATwigExtension(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($root.'/templates', 0777, true);
        file_put_contents($root.'/templates/index.html', 'Hello');
        $project = new Project($root, 'file://'.$root, '^8.0');
        $indexes = new TemplateIndexRegistry();

        try {
            (new ProjectTemplateSnapshotLoader($indexes, new UriToPathConverter()))->load($project, ['sections' => ['twig' => [
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
        $provider = new TemplateCodeActionProvider($documents, $projects, $extractor, $indexes, new UriToPathConverter());

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
