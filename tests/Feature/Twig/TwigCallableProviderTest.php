<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\ProjectDocumentReader;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\Twig\TwigCallableDeclarationExtractor;
use Symfony\Lsp\Feature\Twig\TwigCallableIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigCallableKind;
use Symfony\Lsp\Feature\Twig\TwigCallableProvider;
use Symfony\Lsp\Feature\Twig\TwigCallableReferenceExtractor;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigCallableProviderTest extends TestCase
{
    public function testExtractsCustomTwigFunctionsAndFilters(): void
    {
        $extractor = $this->declarationExtractor($converter = new PositionConverter());
        $source = <<<'PHP'
            <?php
            namespace App\Twig;

            use App\Twig\AppExtensionRuntime as Runtime;
            use Twig\TwigFilter as Filter;
            use Twig\TwigFunction as FunctionDefinition;

            final class AppExtension
            {
                public function getFilters(): array
                {
                    return [
                        new Filter('filter_name', [Runtime::class, 'doSomething']),
                        new Filter(name: 'named_filter', callable: $this->localFilter(...)),
                    ];
                }

                public function getFunctions(): array
                {
                    return [
                        new FunctionDefinition('function_name', [Runtime::class, 'doSomething']),
                        new FunctionDefinition('dynamic_name', $dynamicCallable),
                        new OtherDefinition('ignored_name', [Runtime::class, 'ignored']),
                    ];
                }

                public function configure(): void
                {
                    new FunctionDefinition('ignored_direct_function', [Runtime::class, 'ignored']);
                    new Filter('ignored_direct_filter', [Runtime::class, 'ignored']);
                }

                private function localFilter(string $value): string { return $value; }
            }
            PHP;

        $declarations = $extractor->extract('file:///workspace/src/Twig/AppExtension.php', $source)->declarations();

        self::assertSame([
            [TwigCallableKind::Filter, 'filter_name', 'App\Twig\AppExtensionRuntime', 'doSomething'],
            [TwigCallableKind::Filter, 'named_filter', 'App\Twig\AppExtension', 'localFilter'],
            [TwigCallableKind::Function, 'function_name', 'App\Twig\AppExtensionRuntime', 'doSomething'],
            [TwigCallableKind::Function, 'dynamic_name', null, null],
        ], array_map(static fn ($declaration): array => [
            $declaration->kind(),
            $declaration->name(),
            $declaration->className(),
            $declaration->method(),
        ], $declarations));
        $functionOffset = strpos($source, 'function_name');
        self::assertIsInt($functionOffset);
        self::assertSame($converter->toPosition($source, $functionOffset)->line(), $declarations[2]->range()->start()->line());
        self::assertSame($converter->toPosition($source, $functionOffset)->character(), $declarations[2]->range()->start()->character());
    }

    public function testProvidesHoverAndDefinitionForCustomTwigFunctionsAndFilters(): void
    {
        $converter = new PositionConverter();
        $phpParser = new TolerantPhpParser(new Parser());
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $extensionUri = 'file:///workspace/src/Twig/AppExtension.php';
        $extensionText = <<<'PHP'
            <?php
            namespace App\Twig;

            use Twig\TwigFilter;
            use Twig\TwigFunction;

            final class AppExtension
            {
                public function getFilters(): array
                {
                    return [new TwigFilter('filter_name', [AppExtensionRuntime::class, 'doSomething'])];
                }

                public function getFunctions(): array
                {
                    return [
                        new TwigFunction('function_name', [AppExtensionRuntime::class, 'doSomething']),
                        new TwigFunction('dynamic_name', $dynamicCallable),
                        new TwigFunction('outside_name', [OutsideRuntime::class, 'outside']),
                    ];
                }
            }
            PHP;
        $runtimeUri = 'file:///workspace/src/Twig/AppExtensionRuntime.php';
        $runtimeText = <<<'PHP'
            <?php
            namespace App\Twig;

            final class AppExtensionRuntime
            {
                /** Formats the value for display. */
                public function doSomething(string $value): string
                {
                    return $value;
                }
            }
            PHP;
        $outsideUri = 'file:///outside/OutsideRuntime.php';
        $outsideText = <<<'PHP'
            <?php
            namespace App\Twig;

            final class OutsideRuntime
            {
                /** Must not be exposed. */
                public function outside(): void {}
            }
            PHP;
        $twigUri = 'file:///workspace/templates/page.html.twig';
        $twigText = <<<'TWIG'
            {{ function_name('value') }}
            {{ "}}" ~ function_name('value') }}
            {{ broken ??? }}
            {{ item|filter_name }}
            {{ {'nested': {}}|filter_name }}
            {{ dynamic_name() }}
            {{ outside_name() }}
            {{ object.function_name() }}
            {{ path('home') }}
            {# {{ function_name() }} #}
            Plain function_name() text
            TWIG;
        $documents->open(new Document($twigUri, 'twig', 1, $twigText));
        $documents->open(new Document($runtimeUri, 'php', 1, $runtimeText));
        $documents->open(new Document($outsideUri, 'php', 1, $outsideText));
        $indexes = new TwigCallableIndexRegistry();
        $indexes->forProject($project)->replace($this->declarationExtractor($converter, $phpParser)->extract($extensionUri, $extensionText));
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $classExtractor = new PhpClassDeclarationExtractor($converter, $phpParser);
        $classIndexes->forProject($project)->replace(
            new DependencyInjectionSourceFacts($runtimeUri, classes: $classExtractor->extract($runtimeUri, $runtimeText)),
            new DependencyInjectionSourceFacts($outsideUri, classes: $classExtractor->extract($outsideUri, $outsideText)),
        );
        $provider = new TwigCallableProvider(
            new DocumentContextResolver($documents, $projects),
            $converter,
            $protocol = new LspProtocolMapper(),
            $indexes,
            new TwigCallableReferenceExtractor(new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $commentParser = new TwigCommentParser()), $commentParser),
            $classIndexes,
            new ProjectDocumentReader($documents, new ProjectPathResolver(new UriToPathConverter())),
            $phpParser,
        );

        $functionHover = [
            'contents' => [
                'kind' => 'markdown',
                'value' => "Twig function: `function_name`\n\nCallable: `App\\Twig\\AppExtensionRuntime::doSomething`\n\n```php\npublic function doSomething(string \$value): string\n```\n\nFormats the value for display.",
            ],
        ];
        self::assertSame($functionHover, $provider->hover($this->params($twigUri, $twigText, 'function_name', $converter)));
        $delimiterOffset = strpos($twigText, 'function_name', \strlen('{{ function_name'));
        self::assertIsInt($delimiterOffset);
        self::assertSame($functionHover, $provider->hover($this->params($twigUri, $twigText, 'function_name', $converter, $delimiterOffset)));
        $filterHover = [
            'contents' => [
                'kind' => 'markdown',
                'value' => "Twig filter: `filter_name`\n\nCallable: `App\\Twig\\AppExtensionRuntime::doSomething`\n\n```php\npublic function doSomething(string \$value): string\n```\n\nFormats the value for display.",
            ],
        ];
        self::assertSame($filterHover, $provider->hover($this->params($twigUri, $twigText, 'filter_name', $converter)));
        $nestedFilterOffset = strpos($twigText, 'filter_name', strpos($twigText, 'filter_name') + 1);
        self::assertIsInt($nestedFilterOffset);
        self::assertSame($filterHover, $provider->hover($this->params($twigUri, $twigText, 'filter_name', $converter, $nestedFilterOffset)));
        $methodOffset = strpos($runtimeText, 'doSomething');
        self::assertIsInt($methodOffset);
        self::assertSame([
            $protocol->location(
                $runtimeUri,
                new Range(
                    $converter->toPosition($runtimeText, $methodOffset),
                    $converter->toPosition($runtimeText, $methodOffset + \strlen('doSomething')),
                ),
            ),
        ], $provider->definition($this->params($twigUri, $twigText, 'function_name', $converter)));

        $dynamicOffset = strpos($extensionText, 'dynamic_name');
        self::assertIsInt($dynamicOffset);
        self::assertSame([
            $protocol->location(
                $extensionUri,
                new Range(
                    $converter->toPosition($extensionText, $dynamicOffset),
                    $converter->toPosition($extensionText, $dynamicOffset + \strlen('dynamic_name')),
                ),
            ),
        ], $provider->definition($this->params($twigUri, $twigText, 'dynamic_name', $converter)));

        self::assertSame([
            'contents' => [
                'kind' => 'markdown',
                'value' => "Twig function: `outside_name`\n\nCallable: `App\\Twig\\OutsideRuntime::outside`",
            ],
        ], $provider->hover($this->params($twigUri, $twigText, 'outside_name', $converter)));
        $outsideOffset = strpos($extensionText, 'outside_name');
        self::assertIsInt($outsideOffset);
        self::assertSame([
            $protocol->location(
                $extensionUri,
                new Range(
                    $converter->toPosition($extensionText, $outsideOffset),
                    $converter->toPosition($extensionText, $outsideOffset + \strlen('outside_name')),
                ),
            ),
        ], $provider->definition($this->params($twigUri, $twigText, 'outside_name', $converter)));

        self::assertNull($provider->hover($this->params($twigUri, $twigText, 'path', $converter)));
        self::assertNull($provider->hover($this->params($twigUri, $twigText, 'function_name', $converter, strrpos($twigText, 'function_name'))));
        self::assertNull($provider->hover($this->params($twigUri, $twigText, 'function_name', $converter, strpos($twigText, 'Plain function_name') + \strlen('Plain '))));
    }

    public function testKeepsUnsavedTwigCallableDeclarationsAuthoritative(): void
    {
        $extractor = $this->declarationExtractor(new PositionConverter());
        $index = (new TwigCallableIndexRegistry())->forProject(new Project('/workspace', 'file:///workspace', '^8.0'));
        $uri = 'file:///workspace/src/Twig/AppExtension.php';
        $saved = "<?php class Extension { public function getFunctions(): array { return [new \\Twig\\TwigFunction('saved_name', null)]; } }";
        $unsaved = "<?php class Extension { public function getFunctions(): array { return [new \\Twig\\TwigFunction('unsaved_name', null)]; } }";

        $index->replace($extractor->extract($uri, $saved));
        $index->overlay($extractor->extract($uri, $unsaved));

        self::assertSame([], $index->declarations(TwigCallableKind::Function, 'saved_name'));
        self::assertCount(1, $index->declarations(TwigCallableKind::Function, 'unsaved_name'));

        $index->removeOverlay($uri);

        self::assertCount(1, $index->declarations(TwigCallableKind::Function, 'saved_name'));
        self::assertSame([], $index->declarations(TwigCallableKind::Function, 'unsaved_name'));
    }

    private function declarationExtractor(PositionConverter $converter, ?TolerantPhpParser $parser = null): TwigCallableDeclarationExtractor
    {
        return new TwigCallableDeclarationExtractor($converter, $parser ?? new TolerantPhpParser(new Parser()));
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(string $uri, string $text, string $needle, PositionConverter $converter, int|false|null $offset = null): array
    {
        $offset = null === $offset ? strpos($text, $needle) : $offset;
        self::assertIsInt($offset);
        $position = $converter->toPosition($text, $offset + intdiv(\strlen($needle), 2));

        return [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ];
    }
}
