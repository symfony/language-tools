<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Twig\ProjectTemplateSnapshotLoader;
use Symfony\Lsp\Feature\Twig\TemplateCodeActionProvider;
use Symfony\Lsp\Feature\Twig\TemplateCompletionContext;
use Symfony\Lsp\Feature\Twig\TemplateCompletionHandler;
use Symfony\Lsp\Feature\Twig\TemplateDeclaration;
use Symfony\Lsp\Feature\Twig\TemplateIndexRegistry;
use Symfony\Lsp\Feature\Twig\TemplateNameResolver;
use Symfony\Lsp\Feature\Twig\TemplateNavigationProvider;
use Symfony\Lsp\Feature\Twig\TemplateReference;
use Symfony\Lsp\Feature\Twig\TemplateReferenceExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponent;
use Symfony\Lsp\Feature\Twig\TwigComponentCodeLensProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentCompletionProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentDiagnosticProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponentIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigComponentNameResolver;
use Symfony\Lsp\Feature\Twig\TwigComponentPhpExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponentRelationshipProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentResolver;
use Symfony\Lsp\Feature\Twig\TwigComponentTemplateExtractor;
use Symfony\Lsp\Feature\Twig\TwigVariableProvider;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigArgumentParser;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Parser\Twig\TwigTypeDeclarationParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class TemplateProviderTest extends TestCase
{
    public function testExtractsValidReferencesAroundMalformedTwigWithoutMatchingComments(): void
    {
        $converter = new PositionConverter();
        $extractor = new TemplateReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser()), new TwigCallArgumentResolver(new TwigArgumentParser()), new QuotedArgumentMatcher($converter), new PhpCommentParser(), new TolerantPhpParser(new Parser()), new PhpLiteralArrayKeyParser(), new BalancedDelimiterMatcher());
        $references = $extractor->extract(new SourceDocument('file:///workspace/templates/page.html.twig', 'twig', <<<'TWIG'
            {# {% include 'ignored.html.twig' %} #}
            {## {% include 'documented-outer.html.twig' %} ##}
            {% types {
                ## include('documented.html.twig')
                article: 'string',
            } %}
            {% verbatim %}
                {{ include('verbatim.html.twig') }}
            {% endverbatim %}
            {{ include(template_name('ignored.html.twig')) }}
            {% extends 'base.html.twig' %}
            {{ include('card.html.twig') }}
            {{ include(variables: {}, template: 'named-card.html.twig') }}
            {{ source(name = 'named-source.html.twig') }}
            {{ include('unfinished.html.twig'
            TWIG));

        self::assertSame(
            ['base.html.twig', 'card.html.twig', 'named-card.html.twig', 'named-source.html.twig'],
            array_map(static fn (TemplateReference $reference): string => $reference->name, $references),
        );
    }

    public function testDecodesEscapedTwigTemplateNames(): void
    {
        $converter = new PositionConverter();
        $extractor = new TemplateReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser()), new TwigCallArgumentResolver(new TwigArgumentParser()), new QuotedArgumentMatcher($converter), new PhpCommentParser(), new TolerantPhpParser(new Parser()), new PhpLiteralArrayKeyParser(), new BalancedDelimiterMatcher());
        $text = <<<'TWIG'
            {% extends "layout \"wide\".html.twig" %}
            {{ source("fragment \"compact\".html.twig") }}
            TWIG;

        $references = $extractor->extract(new SourceDocument('file:///workspace/templates/page.html.twig', 'twig', $text));

        self::assertSame(
            ['layout "wide".html.twig', 'fragment "compact".html.twig'],
            array_map(static fn (TemplateReference $reference): string => $reference->name, $references),
        );
        self::assertSame(
            ['layout \"wide\".html.twig', 'fragment \"compact\".html.twig'],
            array_map(static fn (TemplateReference $reference): string => substr(
                $text,
                $converter->toByteOffset($text, $reference->range->start),
                $converter->toByteOffset($text, $reference->range->end) - $converter->toByteOffset($text, $reference->range->start),
            ), $references),
        );
    }

    public function testExtractsPhpRenderCallsFromParserFacts(): void
    {
        $converter = new PositionConverter();
        $extractor = new TemplateReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser()), new TwigCallArgumentResolver(new TwigArgumentParser()), new QuotedArgumentMatcher($converter), new PhpCommentParser(), new TolerantPhpParser(new Parser()), new PhpLiteralArrayKeyParser(), new BalancedDelimiterMatcher());
        $references = $extractor->extract(new SourceDocument('file:///workspace/src/Controller.php', 'php', <<<'PHP'
            <?php
            $this->render(
                parameters: [
                    'user' => ['name' => $name],
                    $dynamic => false,
                    "can\"edit" => true,
                    'after' => true,
                ],
                view: 'named.html.twig',
            );
            $controller->renderView('view.html.twig', array('item' => $item));
            Controller::render('static.html.twig', ['static' => ['inner' => 1], 'after_static' => $value]);
            Controller::render('static-long.html.twig', array('long' => $value));
            $this->render('incomplete.html.twig'
            PHP));

        self::assertSame(
            [
                ['named.html.twig', ['user', 'can"edit', 'after']],
                ['view.html.twig', ['item']],
                ['static.html.twig', ['static', 'after_static']],
                ['static-long.html.twig', ['long']],
                ['incomplete.html.twig', []],
            ],
            array_map(static fn (TemplateReference $reference): array => [$reference->name, $reference->variables], $references),
        );
    }

    public function testCompletesRenderContextVariablesAndTwigGlobals(): void
    {
        $converter = new PositionConverter();
        $extractor = new TemplateReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser()), new TwigCallArgumentResolver(new TwigArgumentParser()), new QuotedArgumentMatcher($converter), new PhpCommentParser(), new TolerantPhpParser(new Parser()), new PhpLiteralArrayKeyParser(), new BalancedDelimiterMatcher());
        $reference = $extractor->extract(
            new SourceDocument('file:///workspace/src/Controller.php',
                'php',
                "<?php \$this->render('article/show.html.twig', ['article' => \$article, 'can_edit' => true]);"),
        )[0];
        self::assertSame(['article', 'can_edit'], $reference->variables);

        $uri = 'file:///workspace/templates/article/show.html.twig';
        $text = '{{ art }} {{ app }}';
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'twig', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new TemplateIndexRegistry();
        $indexes->forProject($project)->replaceReferences($reference);
        $indexes->forProject($project)->replaceGlobals(['app']);
        $commentParser = new TwigCommentParser();
        $provider = new TwigVariableProvider(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            $indexes,
            new TwigComponentIndexRegistry(),
            $this->templateNameResolver(),
            new TwigTypeDeclarationParser($commentParser),
            $commentParser,
        );
        $position = $converter->toPosition($text, strpos($text, 'art') + 3);

        self::assertSame(['article'], array_column($provider->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]) ?? [], 'label'));
        $hoverPosition = $converter->toPosition($text, strrpos($text, 'app') + 1);
        $hover = $provider->hover([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $hoverPosition->line, 'character' => $hoverPosition->character],
        ]);
        self::assertIsArray($hover);
        self::assertIsArray($hover['contents'] ?? null);
        self::assertIsString($hover['contents']['value'] ?? null);
        self::assertStringContainsString('Twig global', $hover['contents']['value']);
    }

    public function testCompletesAndDescribesVariablesDeclaredByTypesTags(): void
    {
        $uri = 'file:///workspace/templates/article/show.html.twig';
        $text = <<<'TWIG'
            {% types {
                ## The article to display.
                article: 'App\\Entity\\Article',

                ## Whether to highlight the article.
                featured?: 'boolean',
                café: 'string',
            } %}
            {{ arti }}
            {{ featured }}
            {{ café }}
            TWIG;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'twig', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $commentParser = new TwigCommentParser();
        $provider = new TwigVariableProvider(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            new TemplateIndexRegistry(),
            new TwigComponentIndexRegistry(),
            $this->templateNameResolver(),
            new TwigTypeDeclarationParser($commentParser),
            $commentParser,
        );
        $completionPosition = $converter->toPosition($text, strrpos($text, 'arti') + \strlen('arti'));
        $items = $provider->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $completionPosition->line, 'character' => $completionPosition->character],
        ]);

        self::assertIsArray($items);
        self::assertCount(1, $items);
        self::assertSame('article', $items[0]['label'] ?? null);
        self::assertSame('Twig variable: App\Entity\Article (required)', $items[0]['detail'] ?? null);
        self::assertSame(
            ['kind' => 'markdown', 'value' => 'The article to display.'],
            $items[0]['documentation'] ?? null,
        );

        $unicodePosition = $converter->toPosition($text, strrpos($text, 'café') + \strlen('café'));
        $unicodeItems = $provider->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $unicodePosition->line, 'character' => $unicodePosition->character],
        ]);
        self::assertIsArray($unicodeItems);
        self::assertSame(['café'], array_column($unicodeItems, 'label'));
        self::assertSame('Twig variable: string (required)', $unicodeItems[0]['detail'] ?? null);
        self::assertSame([
            'contents' => [
                'kind' => 'markdown',
                'value' => "Twig variable: `café`\n\nDeclared type: `string`\n\nRequired template variable",
            ],
        ], $provider->hover([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $unicodePosition->line, 'character' => $unicodePosition->character],
        ]));

        $hoverPosition = $converter->toPosition($text, strrpos($text, 'featured') + 1);
        self::assertSame([
            'contents' => [
                'kind' => 'markdown',
                'value' => "Twig variable: `featured`\n\nDeclared type: `boolean`\n\nOptional template variable\n\nWhether to highlight the article.",
            ],
        ], $provider->hover([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $hoverPosition->line, 'character' => $hoverPosition->character],
        ]));
        $commentPosition = $converter->toPosition($text, strpos($text, 'article to display') + 1);
        self::assertNull($provider->hover([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $commentPosition->line, 'character' => $commentPosition->character],
        ]));
    }

    public function testIndexesAndProvidesTwigComponents(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $converter = new PositionConverter();
        $commentParser = new TwigCommentParser();
        $extractor = $this->componentExtractor($converter, $commentParser);
        $classUri = 'file:///workspace/src/Twig/Alert.php';
        $classText = <<<'PHP'
            <?php
            namespace App\Twig;

            use Symfony\UX\TwigComponent\Attribute\AsTwigComponent as Component;

            #[Component(template: 'components/Alert.html.twig')]
            final class Alert
            {
                public string $title;
            }
            PHP;
        $templateUri = 'file:///workspace/templates/components/Alert.html.twig';
        $usageUri = 'file:///workspace/templates/page.html.twig';
        $usageText = "{## Use <twig:Documented /> or component('Documented') in examples. #}\n<twig:Alert title=\"Hello\" />";
        $indexes = new TwigComponentIndexRegistry();
        $indexes->forProject($project)->replace(
            $extractor->extract($project, new SourceDocument($classUri, 'php', $classText)),
            $extractor->extract($project, new SourceDocument($templateUri, 'twig', '{{ title }}')),
            $extractor->extract($project, new SourceDocument($usageUri, 'twig', $usageText)),
        );
        $component = $indexes->forProject($project)->get('Alert');
        self::assertInstanceOf(TwigComponent::class, $component);
        self::assertSame('App\\Twig\\Alert', $component->className);
        self::assertSame(['title'], $component->properties);

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
        $indexes->forProject($project)->replaceRuntime(true, [], 'components');
        $templateIndexes = new TemplateIndexRegistry();
        $templateIndexes->forProject($project)->replaceRuntime(true);
        $documentResolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $componentResolver = new TwigComponentResolver($documentResolver, new PositionedSourceSymbolResolver($converter), $indexes, $templateIndexes, $extractor);
        $completionProvider = new TwigComponentCompletionProvider($documentResolver, $converter, $protocol, $indexes, $componentResolver, $commentParser);
        $relationshipProvider = new TwigComponentRelationshipProvider($protocol, $indexes, $componentResolver);
        $diagnosticProvider = new TwigComponentDiagnosticProvider($documentResolver, $protocol, $indexes, $templateIndexes, $extractor, $componentResolver);
        $codeLensProvider = new TwigComponentCodeLensProvider($documentResolver, $protocol, $indexes, $extractor);
        $completionPosition = $converter->toPosition($completionText, \strlen($completionText));
        self::assertSame(['Alert'], array_column($completionProvider->complete([
            'textDocument' => ['uri' => $completionUri],
            'position' => ['line' => $completionPosition->line, 'character' => $completionPosition->character],
        ]) ?? [], 'label'));
        $commentUri = 'file:///workspace/templates/comment.html.twig';
        $commentText = '{## Use <twig:Al in examples. #}';
        $documents->open(new Document($commentUri, 'twig', 1, $commentText));
        $commentPosition = $converter->toPosition($commentText, strpos($commentText, 'Al') + 2);
        self::assertNull($completionProvider->complete([
            'textDocument' => ['uri' => $commentUri],
            'position' => ['line' => $commentPosition->line, 'character' => $commentPosition->character],
        ]));

        $usagePosition = $converter->toPosition($usageText, strrpos($usageText, 'Alert') + 1);
        $params = [
            'textDocument' => ['uri' => $usageUri],
            'position' => ['line' => $usagePosition->line, 'character' => $usagePosition->character],
        ];
        self::assertSame([$classUri, $templateUri], array_column($relationshipProvider->definition($params) ?? [], 'uri'));
        self::assertCount(1, $relationshipProvider->references($params) ?? []);
        $hover = $relationshipProvider->hover($params);
        self::assertIsArray($hover);
        self::assertIsArray($hover['contents'] ?? null);
        self::assertIsString($hover['contents']['value'] ?? null);
        self::assertStringContainsString('Properties: `title`', $hover['contents']['value']);
        self::assertSame([], $diagnosticProvider->diagnostics(['textDocument' => ['uri' => $usageUri]]));
        $lenses = $codeLensProvider->codeLenses(['textDocument' => ['uri' => $classUri]]);
        self::assertIsArray($lenses);
        self::assertCount(1, $lenses);
        self::assertIsArray($lenses[0]['command'] ?? null);
        self::assertSame('1 Twig component usage', $lenses[0]['command']['title'] ?? null);
        $commentParser = new TwigCommentParser();
        $variableProvider = new TwigVariableProvider(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            new TemplateIndexRegistry(),
            $indexes,
            $this->templateNameResolver(),
            new TwigTypeDeclarationParser($commentParser),
            $commentParser,
        );
        $variablePosition = $converter->toPosition($componentTemplateText, strpos($componentTemplateText, 'ti') + 2);
        self::assertSame(['title'], array_column($variableProvider->complete([
            'textDocument' => ['uri' => $templateUri],
            'position' => ['line' => $variablePosition->line, 'character' => $variablePosition->character],
        ]) ?? [], 'label'));
    }

    public function testDiagnosesUnknownComponentsOnlyWithCompleteRuntimeMetadata(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $converter = new PositionConverter();
        $commentParser = new TwigCommentParser();
        $extractor = $this->componentExtractor($converter, $commentParser);
        $usageUri = 'file:///workspace/templates/page.html.twig';
        $usageText = "{## Use <twig:Documented /> in examples. #}\n<twig:ux:icon name=\"x\" />\n<twig:uX:iCoN name=\"x\" />\n<twig:Alert />\n<twig:alert />\n<twig:Card />\n<twig:acme:badge />\n<twig:Unknown />";
        $documents = new DocumentStore();
        $documents->open(new Document($usageUri, 'twig', 1, $usageText));
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = new TwigComponentIndexRegistry();
        $indexes->forProject($project)->replace(
            $extractor->extract($project, new SourceDocument($usageUri, 'twig', $usageText)),
        );
        $templateIndexes = new TemplateIndexRegistry();
        $documentResolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $componentResolver = new TwigComponentResolver($documentResolver, new PositionedSourceSymbolResolver($converter), $indexes, $templateIndexes, $extractor);
        $provider = new TwigComponentDiagnosticProvider($documentResolver, $protocol, $indexes, $templateIndexes, $extractor, $componentResolver);
        $params = ['textDocument' => ['uri' => $usageUri]];

        $withoutRuntimeMetadata = $provider->diagnostics($params);
        self::assertNull($withoutRuntimeMetadata);

        $indexes->forProject($project)->replaceRuntime(true, ['ux:icon'], 'components', ['ux:icon']);
        $withoutTemplateMetadata = $provider->diagnostics($params);
        self::assertNull($withoutTemplateMetadata);

        $range = new Range(new Position(0, 0), new Position(0, 0));
        $templateIndexes->forProject($project)->replaceRuntime(
            true,
            new TemplateDeclaration('components/Alert.html.twig', 'file:///workspace/templates/components/Alert.html.twig', $range),
            new TemplateDeclaration('components/Card/index.html.twig', 'file:///workspace/templates/components/Card/index.html.twig', $range),
            new TemplateDeclaration('@acme/components/badge.html.twig', 'file:///workspace/vendor/acme/bundle/templates/components/badge.html.twig', $range),
        );
        $diagnostics = $provider->diagnostics($params);
        self::assertIsArray($diagnostics);
        self::assertSame(
            [
                'Twig component "alert" does not exist.',
                'Twig component "Unknown" does not exist.',
            ],
            array_column($diagnostics, 'message'),
        );

        $indexes->forProject($project)->replaceRuntime(false, [], 'components');
        $withIncompleteRuntimeNames = $provider->diagnostics($params);
        self::assertNull($withIncompleteRuntimeNames);
    }

    public function testCompletesBundleProvidedAndAnonymousComponentNames(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $converter = new PositionConverter();
        $commentParser = new TwigCommentParser();
        $extractor = $this->componentExtractor($converter, $commentParser);
        $completionUri = 'file:///workspace/templates/completion.html.twig';
        $completionText = '<twig:';
        $documents = new DocumentStore();
        $documents->open(new Document($completionUri, 'twig', 1, $completionText));
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = new TwigComponentIndexRegistry();
        $indexes->forProject($project)->replaceRuntime(true, ['ux:icon'], 'components');
        $templateIndexes = new TemplateIndexRegistry();
        $range = new Range(new Position(0, 0), new Position(0, 0));
        $templateIndexes->forProject($project)->replaceRuntime(
            true,
            new TemplateDeclaration('components/Alert.html.twig', 'file:///workspace/templates/components/Alert.html.twig', $range),
            new TemplateDeclaration('components/Card/index.html.twig', 'file:///workspace/templates/components/Card/index.html.twig', $range),
            new TemplateDeclaration('@acme/components/badge.html.twig', 'file:///workspace/vendor/acme/bundle/templates/components/badge.html.twig', $range),
            new TemplateDeclaration('page.html.twig', 'file:///workspace/templates/page.html.twig', $range),
        );
        $documentResolver = new DocumentContextResolver($documents, $projects);
        $componentResolver = new TwigComponentResolver($documentResolver, new PositionedSourceSymbolResolver($converter), $indexes, $templateIndexes, $extractor);
        $provider = new TwigComponentCompletionProvider($documentResolver, $converter, new LspProtocolMapper(), $indexes, $componentResolver, $commentParser);

        $position = $converter->toPosition($completionText, \strlen($completionText));
        $items = $provider->complete([
            'textDocument' => ['uri' => $completionUri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]);

        self::assertSame(['Alert', 'Card', 'acme:badge', 'ux:icon'], array_column($items ?? [], 'label'));
    }

    public function testResolvesBundleTemplateNames(): void
    {
        $resolver = $this->templateNameResolver();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');

        self::assertSame('@AcmeBundle/article/show.html.twig', $resolver->resolve($project, 'file:///workspace/templates/bundles/AcmeBundle/article/show.html.twig'));
        self::assertSame('bundles/AcmeBundle/article/show.html.twig', $resolver->relative($project, 'file:///workspace/templates/bundles/AcmeBundle/article/show.html.twig'));
        self::assertNull($resolver->resolve($project, 'file:///workspace/src/article/show.html.twig'));
    }

    private function componentExtractor(PositionConverter $converter, TwigCommentParser $comments): TwigComponentExtractor
    {
        $names = new TwigComponentNameResolver($this->templateNameResolver());

        return new TwigComponentExtractor(
            new TolerantPhpParser(new Parser()),
            new TwigComponentPhpExtractor($converter, $names),
            new TwigComponentTemplateExtractor(
                $converter,
                $names,
                new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $comments),
                new TwigCallArgumentResolver(new TwigArgumentParser()),
                $comments,
            ),
        );
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
            (new ProjectTemplateSnapshotLoader($indexes, new UriToPathConverter(), new ContainerPathMapper(new RuntimeConfiguration())))->load($project, [
                'complete' => true,
                'paths' => [['namespace' => '(None)', 'path' => $root.'/templates']],
            ]);

            self::assertSame('file://'.$root.'/templates/index.html', $indexes->forProject($project)->get('index.html')?->uri);
        } finally {
            @unlink($root.'/templates/index.html');
            @rmdir($root.'/templates');
            @rmdir($root);
        }
    }

    public function testIndexesTemplatesFromContainerLoaderPaths(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($root.'/templates', 0777, true);
        file_put_contents($root.'/templates/index.html.twig', 'Hello');
        $project = new Project($root, 'file://'.$root, '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['containerProjectRoot' => '/app']);
        $indexes = new TemplateIndexRegistry();

        try {
            (new ProjectTemplateSnapshotLoader($indexes, new UriToPathConverter(), new ContainerPathMapper($configuration)))->load($project, [
                'complete' => true,
                'paths' => [['namespace' => '(None)', 'path' => '/app/templates']],
            ]);

            self::assertSame('file://'.$root.'/templates/index.html.twig', $indexes->forProject($project)->get('index.html.twig')?->uri);
        } finally {
            @unlink($root.'/templates/index.html.twig');
            @rmdir($root.'/templates');
            @rmdir($root);
        }
    }

    public function testIndexesTemplatesAroundUnreadableDirectories(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || (\function_exists('posix_geteuid') && 0 === posix_geteuid())) {
            self::markTestSkipped('Directory permissions are not enforced in this environment.');
        }

        $root = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($root.'/templates/admin', 0777, true);
        file_put_contents($root.'/templates/index.html.twig', 'Hello');
        chmod($root.'/templates/admin', 0000);
        $project = new Project($root, 'file://'.$root, '^8.0');
        $indexes = new TemplateIndexRegistry();

        try {
            (new ProjectTemplateSnapshotLoader($indexes, new UriToPathConverter(), new ContainerPathMapper(new RuntimeConfiguration())))->load($project, [
                'complete' => true,
                'paths' => [['namespace' => '(None)', 'path' => $root.'/templates']],
            ]);

            self::assertSame('file://'.$root.'/templates/index.html.twig', $indexes->forProject($project)->get('index.html.twig')?->uri);
        } finally {
            chmod($root.'/templates/admin', 0755);
            @unlink($root.'/templates/index.html.twig');
            @rmdir($root.'/templates/admin');
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
        $extractor = new TemplateReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser()), new TwigCallArgumentResolver(new TwigArgumentParser()), new QuotedArgumentMatcher($converter), new PhpCommentParser(), new TolerantPhpParser(new Parser()), new PhpLiteralArrayKeyParser(), new BalancedDelimiterMatcher());
        $navigation = new TemplateNavigationProvider(new DocumentContextResolver($documents, $projects), new PositionedSourceSymbolResolver($converter), new LspProtocolMapper(), $extractor, $indexes);
        $diagnostics = $navigation->diagnostics(['textDocument' => ['uri' => $uri]]);
        self::assertIsArray($diagnostics);
        $provider = new TemplateCodeActionProvider(new DocumentContextResolver($documents, $projects), $extractor, $indexes, new UriToPathConverter(), new ProjectPathResolver(new UriToPathConverter()), new LspProtocolMapper());

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

    public function testDoesNotCreateTemplatesThroughExternalSymlinks(): void
    {
        $directory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        $root = $directory.'/project';
        mkdir($root.'/src', 0777, true);
        mkdir($root.'/templates', 0777, true);
        mkdir($directory.'/external', 0777, true);
        symlink($directory.'/external', $root.'/templates/external');
        $converter = new UriToPathConverter();
        $uri = $converter->toUri($root.'/src/Controller.php');
        $text = "<?php \$this->render('external/missing.html.twig');";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project($root, $converter->toUri($root), '^8.0')]);
        $indexes = new TemplateIndexRegistry();
        $indexes->forProject($project)->replaceRuntime(true);
        $positionConverter = new PositionConverter();
        $extractor = new TemplateReferenceExtractor($positionConverter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser()), new TwigCallArgumentResolver(new TwigArgumentParser()), new QuotedArgumentMatcher($positionConverter), new PhpCommentParser(), new TolerantPhpParser(new Parser()), new PhpLiteralArrayKeyParser(), new BalancedDelimiterMatcher());
        $navigation = new TemplateNavigationProvider(new DocumentContextResolver($documents, $projects), new PositionedSourceSymbolResolver($positionConverter), new LspProtocolMapper(), $extractor, $indexes);

        try {
            $diagnostics = $navigation->diagnostics(['textDocument' => ['uri' => $uri]]);
            self::assertIsArray($diagnostics);
            $provider = new TemplateCodeActionProvider(new DocumentContextResolver($documents, $projects), $extractor, $indexes, $converter, new ProjectPathResolver($converter), new LspProtocolMapper());

            self::assertSame([], $provider->actions([
                'textDocument' => ['uri' => $uri],
                'range' => $diagnostics[0]['range'],
                'context' => ['diagnostics' => $diagnostics],
            ]));
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    public function testCompletesAndLinksTemplateNames(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = "<?php \$this->render('article/show.html.twig');";
        [$completion, $navigation, $converter] = $this->providers($uri, 'php', $text);
        $position = $converter->toPosition($text, strpos($text, 'article/sh') + \strlen('article/sh'));
        $params = ['textDocument' => ['uri' => $uri], 'position' => [
            'line' => $position->line, 'character' => $position->character,
        ]];

        self::assertSame(['article/show.html.twig'], array_column($completion->complete($params) ?? [], 'label'));
        self::assertSame(
            'file:///workspace/templates/article/show.html.twig',
            $navigation->links(['textDocument' => ['uri' => $uri]])[0]['target'] ?? null,
        );
    }

    public function testIgnoresTemplateCompletionInsideDocumentationComments(): void
    {
        $uri = 'file:///workspace/templates/page.html.twig';
        $text = "{## Use include('article/sh') in examples. #}";
        [$completion, , $converter] = $this->providers($uri, 'twig', $text);
        $position = $converter->toPosition($text, strpos($text, 'article/sh') + \strlen('article/sh'));

        self::assertNull($completion->complete(['textDocument' => ['uri' => $uri], 'position' => [
            'line' => $position->line, 'character' => $position->character,
        ]]));
    }

    public function testNavigatesReferencesAndDiagnosesMissingTemplates(): void
    {
        $uri = 'file:///workspace/templates/page.html.twig';
        $text = "{% extends 'article/show.html.twig' %}\n{% include 'missing.html.twig' %}";
        [, $navigation, $converter] = $this->providers($uri, 'twig', $text);
        $position = $converter->toPosition($text, strpos($text, 'article/show') + 1);
        $params = ['textDocument' => ['uri' => $uri], 'position' => [
            'line' => $position->line, 'character' => $position->character,
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

    public function testResolvesTemplateNamesWithALeadingDotSlash(): void
    {
        $uri = 'file:///workspace/templates/page.html.twig';
        $completionText = "{{ source(name: './sn') }}";
        [$completion, , $completionConverter] = $this->providers($uri, 'twig', $completionText);
        $completionPosition = $completionConverter->toPosition($completionText, (int) strpos($completionText, "')"));
        self::assertSame(['snippet.txt'], array_column($completion->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $completionPosition->line, 'character' => $completionPosition->character],
        ]) ?? [], 'label'));

        $text = "{{ source(name = './snippet.txt') }}";
        [, $navigation, $converter] = $this->providers($uri, 'twig', $text);
        $position = $converter->toPosition($text, strpos($text, 'snippet') + 1);
        $params = ['textDocument' => ['uri' => $uri], 'position' => [
            'line' => $position->line, 'character' => $position->character,
        ]];

        self::assertSame([], $navigation->diagnostics(['textDocument' => ['uri' => $uri]]));
        self::assertSame(
            ['file:///workspace/templates/snippet.txt'],
            array_column($navigation->definition($params) ?? [], 'uri'),
        );
        self::assertSame(
            'file:///workspace/templates/snippet.txt',
            $navigation->links(['textDocument' => ['uri' => $uri]])[0]['target'] ?? null,
        );
        self::assertSame([$uri], array_column($navigation->references($params) ?? [], 'uri'));
    }

    public function testKeepsLeadingDotSlashNamesInTheMainNamespace(): void
    {
        $uri = 'file:///workspace/templates/page.html.twig';
        $completionText = "{{ source('./@Ad') }}";
        [$completion, , $completionConverter] = $this->providers($uri, 'twig', $completionText);
        $completionPosition = $completionConverter->toPosition($completionText, (int) strpos($completionText, "')"));
        self::assertSame([], $completion->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $completionPosition->line, 'character' => $completionPosition->character],
        ]) ?? []);

        $text = "{{ source('./@Admin/foo.html.twig') }}";
        [, $navigation, $converter] = $this->providers($uri, 'twig', $text);
        $position = $converter->toPosition($text, strpos($text, '@Admin') + 1);
        $params = ['textDocument' => ['uri' => $uri], 'position' => [
            'line' => $position->line, 'character' => $position->character,
        ]];

        self::assertSame(['template.not_found'], array_column($navigation->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));
        self::assertNull($navigation->definition($params));
    }

    public function testNavigatesFromDependencyOwnedOpenDocumentsWithoutIndexedReferences(): void
    {
        $uri = 'file:///workspace/vendor/acme/templates/page.html.twig';
        $text = "{% extends 'article/show.html.twig' %}";
        [, $navigation, $converter] = $this->providers($uri, 'twig', $text, indexReferences: false);
        $position = $converter->toPosition($text, strpos($text, 'article') + 1);

        self::assertSame(
            ['file:///workspace/templates/article/show.html.twig'],
            array_column($navigation->definition([
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $position->line, 'character' => $position->character],
            ]) ?? [], 'uri'),
        );
    }

    public function testDoesNotDiagnoseTwigFilesOutsideRuntimeLoaderPaths(): void
    {
        $uri = 'file:///workspace/book/design/templates/base.html.twig';
        $text = "{% extends 'missing.html.twig' %}";
        [, $navigation] = $this->providers($uri, 'twig', $text);

        self::assertSame([], $navigation->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    /** @return array{TemplateCompletionHandler, TemplateNavigationProvider, PositionConverter} */
    private function providers(string $uri, string $languageId, string $text, bool $indexReferences = true): array
    {
        $documents = new DocumentStore();
        $documents->open(new Document($uri, $languageId, 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new TemplateIndexRegistry();
        $indexes->forProject($project)->replaceRuntime(
            true,
            new TemplateDeclaration(
                'article/show.html.twig',
                'file:///workspace/templates/article/show.html.twig',
                new Range(new Position(0, 0), new Position(0, 0)),
            ),
            new TemplateDeclaration(
                'page.html.twig',
                'file:///workspace/templates/page.html.twig',
                new Range(new Position(0, 0), new Position(0, 0)),
            ),
            new TemplateDeclaration(
                'snippet.txt',
                'file:///workspace/templates/snippet.txt',
                new Range(new Position(0, 0), new Position(0, 0)),
            ),
            new TemplateDeclaration(
                '@Admin/foo.html.twig',
                'file:///workspace/vendor/admin/templates/foo.html.twig',
                new Range(new Position(0, 0), new Position(0, 0)),
            ),
        );
        $converter = new PositionConverter();
        $commentParser = new TwigCommentParser();
        $extractor = new TemplateReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $commentParser), new TwigCallArgumentResolver(new TwigArgumentParser()), new QuotedArgumentMatcher($converter), new PhpCommentParser(), new TolerantPhpParser(new Parser()), new PhpLiteralArrayKeyParser(), new BalancedDelimiterMatcher());
        if ($indexReferences) {
            $indexes->forProject($project)->replaceReferences(...$extractor->extract(new SourceDocument($uri, $languageId, $text)));
        }
        $resolver = new DocumentContextResolver($documents, $projects);

        return [
            new TemplateCompletionHandler($resolver, $converter, new LspProtocolMapper(), $indexes, $commentParser, new PhpCommentParser()),
            new TemplateNavigationProvider($resolver, new PositionedSourceSymbolResolver($converter), new LspProtocolMapper(), $extractor, $indexes),
            $converter,
        ];
    }

    public function testOffersNoTemplateCompletionsInsidePhpComments(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = "<?php // \$this->render('artic";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new TemplateIndexRegistry();
        $converter = new PositionConverter();
        $indexes->forProject($project)->replaceSources(new TemplateDeclaration('article/show.html.twig', 'file:///workspace/templates/article/show.html.twig', new Range(new Position(0, 0), new Position(0, 0))));
        $handler = new TemplateCompletionHandler(new DocumentContextResolver($documents, $projects), $converter, new LspProtocolMapper(), $indexes, new TwigCommentParser(), new PhpCommentParser());
        $position = $converter->toPosition($text, \strlen($text));

        self::assertNull($handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]));
    }

    public function testIgnoresRenderCallsInPhpComments(): void
    {
        $converter = new PositionConverter();
        $extractor = new TemplateReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser()), new TwigCallArgumentResolver(new TwigArgumentParser()), new QuotedArgumentMatcher($converter), new PhpCommentParser(), new TolerantPhpParser(new Parser()), new PhpLiteralArrayKeyParser(), new BalancedDelimiterMatcher());

        $references = $extractor->extract(new SourceDocument('file:///workspace/src/Controller.php', 'php', <<<'PHP'
            <?php
            // $this->render('commented.html.twig');
            /* $this->renderView('blocked.html.twig'); */
            $this->render('live.html.twig');
            PHP));

        self::assertSame(['live.html.twig'], array_map(static fn ($reference): string => $reference->name, $references));
    }

    public function testExtractsTemplateAttributeReferences(): void
    {
        $converter = new PositionConverter();
        $extractor = new TemplateReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser()), new TwigCallArgumentResolver(new TwigArgumentParser()), new QuotedArgumentMatcher($converter), new PhpCommentParser(), new TolerantPhpParser(new Parser()), new PhpLiteralArrayKeyParser(), new BalancedDelimiterMatcher());

        $references = $extractor->extract(new SourceDocument('file:///workspace/src/Controller.php', 'php', <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Bridge\Twig\Attribute\Template;
            use Symfony\Bridge\Twig\Attribute\Template as View;

            class Controller
            {
                #[Template('product/index.html.twig', vars: ['products', 'category'])]
                public function index() {}

                #[View(template: 'product/show.html.twig')]
                public function show() {}

                #[\Symfony\Bridge\Twig\Attribute\Template('product/edit.html.twig', ['product'])]
                public function edit() {}

                #[Template('product/it\'s.html.twig')]
                public function escaped() {}

                #[Template('product/delete.html.twig', vars: array(/* 'removed' */ 'product', 'category'))]
                public function delete() {}

                #[Template('product/export.html.twig', vars: ['pro'.'duct'])]
                public function export() {}

                // #[Template('commented.html.twig')]
                #[Template]
                public function guessed() {}

                #[Template(block: 'content')]
                public function misnamed() {}

                #[Route('/products')]
                public function unrelated() { return $this->render('product/list.html.twig'); }
            }
            PHP));

        self::assertSame(
            [
                ['product/index.html.twig', ['products', 'category']],
                ['product/show.html.twig', []],
                ['product/edit.html.twig', ['product']],
                ["product/it's.html.twig", []],
                ['product/delete.html.twig', ['product', 'category']],
                ['product/export.html.twig', []],
                ['product/list.html.twig', []],
            ],
            array_map(static fn (TemplateReference $reference): array => [$reference->name, $reference->variables], $references),
        );
    }

    #[DataProvider('providePhpCompletionContexts')]
    public function testRecognizesPhpTemplateCompletionContexts(string $text, ?string $expectedPrefix): void
    {
        $converter = new PositionConverter();
        $context = TemplateCompletionContext::create('php', $text, $converter->toPosition($text, \strlen($text)), $converter);

        self::assertSame($expectedPrefix, $context?->prefix);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function providePhpCompletionContexts(): iterable
    {
        yield 'render call' => ["<?php \$this->render('article/sh", 'article/sh'];
        yield 'named render argument' => ["<?php \$this->render(view: 'article/sh", 'article/sh'];
        yield 'attribute' => ["<?php #[Template('article/sh", 'article/sh'];
        yield 'named template argument' => ["<?php #[Template(template: 'article/sh", 'article/sh'];
        yield 'fully qualified attribute' => ["<?php #[\\Symfony\\Bridge\\Twig\\Attribute\\Template('article/sh", 'article/sh'];
        yield 'grouped attributes' => ["<?php #[Route('/articles'), Template('article/sh", 'article/sh'];
        yield 'unrelated qualified attribute' => ["<?php #[App\\Attribute\\Template('article/sh", null];
        yield 'unrelated attribute' => ["<?php #[Route('/articles/sh", null];
    }

    public function testCompletesLinksAndNavigatesTemplateAttributeNames(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = "<?php\nuse Symfony\\Bridge\\Twig\\Attribute\\Template;\nclass Controller { #[Template('article/show.html.twig')] public function show() {} }";
        [$completion, $navigation, $converter] = $this->providers($uri, 'php', $text);
        $position = $converter->toPosition($text, strpos($text, 'article/sh') + \strlen('article/sh'));
        $params = ['textDocument' => ['uri' => $uri], 'position' => [
            'line' => $position->line, 'character' => $position->character,
        ]];

        self::assertSame(['article/show.html.twig'], array_column($completion->complete($params) ?? [], 'label'));
        self::assertSame(
            'file:///workspace/templates/article/show.html.twig',
            $navigation->links(['textDocument' => ['uri' => $uri]])[0]['target'] ?? null,
        );
    }

    public function testDiagnosesMissingTemplateAttributeTargets(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = "<?php\nuse Symfony\\Bridge\\Twig\\Attribute\\Template;\nclass Controller { #[Template('missing.html.twig')] public function show() {} }";
        [, $navigation] = $this->providers($uri, 'php', $text);

        self::assertSame(
            ['template.not_found'],
            array_column($navigation->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'),
        );
    }
}
