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
use Symfony\Lsp\Feature\Twig\TwigCallableSourceFacts;
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
            use Twig\Attribute\AsTwigFilter as FilterAttribute;
            use Twig\Attribute\AsTwigFunction;
            use Twig\Environment;
            use Twig\TwigFilter as Filter;
            use Twig\TwigFunction as FunctionDefinition;

            final class AppExtension
            {
                private const OPTIONS = ['needs_environment' => true];

                public function getFilters(): array
                {
                    return [
                        new Filter('filter_name', [Runtime::class, 'doSomething'], ['needs_context' => true]),
                        new Filter(name: 'named_filter', callable: $this->localFilter(...)),
                    ];
                }

                public function getFunctions(): array
                {
                    return [
                        new FunctionDefinition('function_name', [Runtime::class, 'doSomething'], ['needs_charset' => true, 'needs_environment' => true, 'needs_is_sandboxed' => true, 'is_variadic' => true]),
                        new FunctionDefinition('constant_options', [Runtime::class, 'doSomething'], self::OPTIONS),
                        new FunctionDefinition('dynamic_name', $dynamicCallable),
                        new OtherDefinition('ignored_name', [Runtime::class, 'ignored']),
                    ];
                }

                public function configure(): void
                {
                    new FunctionDefinition('ignored_direct_function', [Runtime::class, 'ignored']);
                    new Filter('ignored_direct_filter', [Runtime::class, 'ignored']);
                }

                #[FilterAttribute('attribute_filter', needsContext: true, needsIsSandboxed: true)]
                public function attributeFilter(Environment $environment, array $context, bool $isSandboxed, string $value): string { return $value; }

                #[AsTwigFunction(name: 'attribute_function', needsCharset: true)]
                public function attributeFunction(string $charset, string $value, mixed ...$options): string { return $value; }

                #[AsTwigFunction('auto_environment')]
                public function autoEnvironment(Environment $environment, string $value): string { return $value; }

                #[AsTwigFunction('legacy_safe', null, null, null, ['html'])]
                public function legacySafe(string $value): string { return $value; }

                #[AsTwigFunction(self::DYNAMIC_NAME)]
                public function dynamicAttribute(): string { return ''; }

                #[AsTwigFunction('private_attribute')]
                private function privateAttribute(): string { return ''; }

                private function localFilter(string $value): string { return $value; }
            }

            trait SharedExtension
            {
                #[AsTwigFunction('trait_function')]
                public function traitFunction(string $value): string { return $value; }
            }
            PHP;

        $declarations = $extractor->extract('file:///workspace/src/Twig/AppExtension.php', $source)->declarations();

        self::assertSame([
            [TwigCallableKind::Filter, 'filter_name', 'App\Twig\AppExtensionRuntime', 'doSomething', false, false, true, false, false, true],
            [TwigCallableKind::Filter, 'named_filter', 'App\Twig\AppExtension', 'localFilter', false, false, false, false, false, true],
            [TwigCallableKind::Function, 'function_name', 'App\Twig\AppExtensionRuntime', 'doSomething', true, true, false, true, true, true],
            [TwigCallableKind::Function, 'constant_options', 'App\Twig\AppExtensionRuntime', 'doSomething', false, false, false, false, false, false],
            [TwigCallableKind::Function, 'dynamic_name', null, null, false, false, false, false, false, true],
            [TwigCallableKind::Filter, 'attribute_filter', 'App\Twig\AppExtension', 'attributeFilter', false, true, true, true, false, true],
            [TwigCallableKind::Function, 'attribute_function', 'App\Twig\AppExtension', 'attributeFunction', true, false, false, false, true, true],
            [TwigCallableKind::Function, 'auto_environment', 'App\Twig\AppExtension', 'autoEnvironment', false, true, false, false, false, true],
            [TwigCallableKind::Function, 'legacy_safe', 'App\Twig\AppExtension', 'legacySafe', false, false, false, false, false, true],
            [TwigCallableKind::Function, 'trait_function', 'App\Twig\SharedExtension', 'traitFunction', false, false, false, false, false, true],
        ], array_map(static fn ($declaration): array => [
            $declaration->kind(),
            $declaration->name(),
            $declaration->className(),
            $declaration->method(),
            $declaration->needsCharset(),
            $declaration->needsEnvironment(),
            $declaration->needsContext(),
            $declaration->needsIsSandboxed(),
            $declaration->isVariadic(),
            $declaration->optionsKnown(),
        ], $declarations));
        $functionOffset = strpos($source, 'function_name');
        self::assertIsInt($functionOffset);
        self::assertSame($converter->toPosition($source, $functionOffset)->line(), $declarations[2]->range()->start()->line());
        self::assertSame($converter->toPosition($source, $functionOffset)->character(), $declarations[2]->range()->start()->character());
    }

    public function testIgnoresIncompleteAttributedDeclarations(): void
    {
        $source = <<<'PHP'
            <?php
            use Twig\Attribute\AsTwigFunction;

            final class AppExtension
            {
                #[AsTwigFunction('complete')]
                public function complete(): string { return ''; }

                #[AsTwigFunction(
                public function incomplete(): string { return ''; }
            }
            PHP;

        $declarations = $this->declarationExtractor(new PositionConverter())->extract('file:///workspace/src/Twig/AppExtension.php', $source)->declarations();

        self::assertSame(['complete'], array_map(static fn ($declaration): string => $declaration->name(), $declarations));
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

            use Twig\Attribute\AsTwigFilter;
            use Twig\Attribute\AsTwigFunction;
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

                /** Builds an attributed value. */
                #[AsTwigFunction('attribute_function')]
                public function attributeFunction(string $value): string { return $value; }

                #[AsTwigFilter('attribute_filter')]
                public function attributeFilter(string $value): string { return $value; }
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
            {{ attribute_function('value') }}
            {{ item|attribute_filter }}
            {{ dynamic_name() }}
            {{ outside_name() }}
            {{ object.function_name() }}
            {{ path('home') }}
            {# {{ function_name() }} #}
            Plain function_name() text
            TWIG;
        $documents->open(new Document($twigUri, 'twig', 1, $twigText));
        $documents->open(new Document($extensionUri, 'php', 1, $extensionText));
        $documents->open(new Document($runtimeUri, 'php', 1, $runtimeText));
        $documents->open(new Document($outsideUri, 'php', 1, $outsideText));
        $referenceExtractor = new TwigCallableReferenceExtractor(new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $commentParser = new TwigCommentParser()), $commentParser, $converter);
        $indexes = new TwigCallableIndexRegistry();
        $indexes->forProject($project)->replace(
            $this->declarationExtractor($converter, $phpParser)->extract($extensionUri, $extensionText),
            new TwigCallableSourceFacts($twigUri, [], $referenceExtractor->all($twigUri, $twigText)),
        );
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $classExtractor = new PhpClassDeclarationExtractor($converter, $phpParser);
        $classIndexes->forProject($project)->replace(
            new DependencyInjectionSourceFacts($extensionUri, classes: $classExtractor->extract($extensionUri, $extensionText)),
            new DependencyInjectionSourceFacts($runtimeUri, classes: $classExtractor->extract($runtimeUri, $runtimeText)),
            new DependencyInjectionSourceFacts($outsideUri, classes: $classExtractor->extract($outsideUri, $outsideText)),
        );
        $provider = new TwigCallableProvider(
            new DocumentContextResolver($documents, $projects),
            $converter,
            $protocol = new LspProtocolMapper(),
            $indexes,
            $referenceExtractor,
            $classIndexes,
            new ProjectDocumentReader($documents, new ProjectPathResolver(new UriToPathConverter())),
            $phpParser,
            new TwigCommentParser(),
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
        self::assertSame([
            'contents' => [
                'kind' => 'markdown',
                'value' => "Twig function: `attribute_function`\n\nCallable: `App\\Twig\\AppExtension::attributeFunction`\n\n```php\npublic function attributeFunction(string \$value): string\n```\n\nBuilds an attributed value.",
            ],
        ], $provider->hover($this->params($twigUri, $twigText, 'attribute_function', $converter)));
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
        $attributeMethodOffset = strpos($extensionText, 'attributeFunction');
        self::assertIsInt($attributeMethodOffset);
        self::assertSame([
            $protocol->location(
                $extensionUri,
                new Range(
                    $converter->toPosition($extensionText, $attributeMethodOffset),
                    $converter->toPosition($extensionText, $attributeMethodOffset + \strlen('attributeFunction')),
                ),
            ),
        ], $provider->definition($this->params($twigUri, $twigText, 'attribute_function', $converter)));

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

        $functionReferences = $provider->references($this->params($twigUri, $twigText, 'function_name', $converter));
        self::assertCount(2, $functionReferences ?? []);
        self::assertSame([$twigUri, $twigUri], array_column($functionReferences ?? [], 'uri'));
        self::assertCount(2, $provider->references($this->params($twigUri, $twigText, 'filter_name', $converter)) ?? []);

        self::assertNull($provider->hover($this->params($twigUri, $twigText, 'path', $converter)));
        self::assertNull($provider->hover($this->params($twigUri, $twigText, 'function_name', $converter, strrpos($twigText, 'function_name'))));
        self::assertNull($provider->hover($this->params($twigUri, $twigText, 'function_name', $converter, strpos($twigText, 'Plain function_name') + \strlen('Plain '))));

        $completions = static function (string $text) use ($provider, $documents, $converter): ?array {
            $uri = 'file:///workspace/templates/completion.html.twig';
            $documents->open(new Document($uri, 'twig', 2, $text));
            $position = $converter->toPosition($text, \strlen($text));
            $items = $provider->complete([
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $position->line(), 'character' => $position->character()],
            ]);

            return null === $items ? null : array_column($items, 'label');
        };
        self::assertSame(['function_name'], $completions('{{ func'));
        self::assertSame(['function_name'], $completions('{{ function_name'));
        self::assertSame(['attribute_function'], $completions('{{ attribute_f'));
        self::assertSame(['attribute_filter', 'filter_name'], $completions('{{ item|'));
        self::assertSame(['filter_name'], $completions('{{ item|fil'));
        self::assertSame(['attribute_filter'], $completions('{{ item|attribute_f'));
        self::assertSame(['function_name'], $completions('{% if f'));
        self::assertSame(['function_name'], $completions('{{ "}}" ~ func'));
        self::assertNull($completions('{{ "say func'));
        self::assertNull($completions('{{ item.func'));
        self::assertNull($completions('Plain func'));
        self::assertNull($completions('{{ done }} func'));
    }

    public function testCompletesAndValidatesNamedArguments(): void
    {
        $converter = new PositionConverter();
        $phpParser = new TolerantPhpParser(new Parser());
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $extensionUri = 'file:///workspace/src/Twig/MediaExtension.php';
        $extensionText = <<<'PHP'
            <?php
            namespace App\Twig;

            use Twig\Attribute\AsTwigFilter;
            use Twig\Attribute\AsTwigFunction;
            use Twig\Environment;
            use Twig\TwigFilter;
            use Twig\TwigFunction;

            final class MediaExtension
            {
                private const DYNAMIC_FLAG = true;
                private const DYNAMIC_OPTIONS = ['needs_environment' => true];

                public function getFilters(): array
                {
                    return [new TwigFilter('shorten', [MediaExtension::class, 'shorten'])];
                }

                public function getFunctions(): array
                {
                    return [
                        new TwigFunction('image', [MediaExtension::class, 'render'], ['needs_environment' => true, 'needs_context' => true]),
                        new TwigFunction('attrs', [MediaExtension::class, 'attrs'], ['is_variadic' => true]),
                        new TwigFunction('dynamic_image', [MediaExtension::class, 'dynamicImage'], self::DYNAMIC_OPTIONS),
                    ];
                }

                public function render(\Twig\Environment $environment, array $context, string $name, int $width = 200, bool $lazy = false): string
                {
                    return $name;
                }

                public function attrs(string $tag, array $arguments): string
                {
                    return $tag;
                }

                public function dynamicImage(\Twig\Environment $environment, string $name): string
                {
                    return $name;
                }

                public function shorten(string $value, int $length = 30): string
                {
                    return $value;
                }

                #[AsTwigFunction('attribute_image', needsContext: true)]
                public function attributeImage(Environment $environment, array $context, string $name, int $width = 200): string
                {
                    return $name;
                }

                #[AsTwigFunction('dynamic_attribute_charset', needsCharset: self::DYNAMIC_FLAG)]
                public function dynamicAttributeCharset(string $charset, string $value): string
                {
                    return $value;
                }

                #[AsTwigFunction('dynamic_attribute_sandbox', needsIsSandboxed: self::DYNAMIC_FLAG)]
                public function dynamicAttributeSandbox(bool $isSandboxed, string $value): string
                {
                    return $value;
                }

                #[AsTwigFilter('attribute_shorten')]
                public function attributeShorten(string $value, int $length = 30): string
                {
                    return $value;
                }

                #[AsTwigFunction('attribute_variadic')]
                public function attributeVariadic(string $name, mixed ...$options): string
                {
                    return $name;
                }

                #[AsTwigFunction('legacy_safe', null, null, null, ['html'])]
                public function legacySafe(string $name, int $width = 200): string
                {
                    return $name;
                }
            }
            PHP;
        $documents->open(new Document($extensionUri, 'php', 1, $extensionText));
        $indexes = new TwigCallableIndexRegistry();
        $indexes->forProject($project)->replace($this->declarationExtractor($converter, $phpParser)->extract($extensionUri, $extensionText));
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $classIndexes->forProject($project)->replace(
            new DependencyInjectionSourceFacts($extensionUri, classes: (new PhpClassDeclarationExtractor($converter, $phpParser))->extract($extensionUri, $extensionText)),
        );
        $provider = new TwigCallableProvider(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            $indexes,
            new TwigCallableReferenceExtractor(new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $commentParser = new TwigCommentParser()), $commentParser, $converter),
            $classIndexes,
            new ProjectDocumentReader($documents, new ProjectPathResolver(new UriToPathConverter())),
            $phpParser,
            new TwigCommentParser(),
        );

        $completions = static function (string $text) use ($provider, $documents, $converter): ?array {
            $uri = 'file:///workspace/templates/arguments.html.twig';
            $documents->open(new Document($uri, 'twig', 2, $text));
            $position = $converter->toPosition($text, \strlen($text));
            $items = $provider->complete([
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $position->line(), 'character' => $position->character()],
            ]);

            return null === $items ? null : array_column($items, 'label');
        };
        self::assertSame(['name', 'width', 'lazy'], $completions('{{ image('));
        self::assertSame(['width'], $completions("{{ image(name: 'a', w"));
        self::assertSame(['width'], $completions("{{ image(name: 'a, lazy: false', w"));
        self::assertSame(['width', 'lazy'], $completions("{{ image(name: 'a', "));
        self::assertSame(['tag'], $completions('{{ attrs('));
        self::assertSame(['name'], $completions('{{ dynamic_image('));
        self::assertSame(['length'], $completions('{{ text|shorten('));
        self::assertSame(['name', 'width'], $completions('{{ attribute_image('));
        self::assertSame(['value'], $completions('{{ dynamic_attribute_charset('));
        self::assertSame(['value'], $completions('{{ dynamic_attribute_sandbox('));
        self::assertSame(['length'], $completions('{{ text|attribute_shorten('));
        self::assertSame(['name'], $completions('{{ attribute_variadic('));
        self::assertSame(['name', 'width'], $completions('{{ legacy_safe('));
        self::assertNull($completions("{{ image(name: 'a"));

        $diagnostics = static function (string $text) use ($provider, $documents): ?array {
            $uri = 'file:///workspace/templates/diagnostics.html.twig';
            $documents->open(new Document($uri, 'twig', 2, $text));

            return $provider->diagnostics(['textDocument' => ['uri' => $uri]]);
        };
        $unknown = $diagnostics("{{ image(name: 'a', wdith: 3) }}\n{{ text|shorten(size: 5) }}\n");
        self::assertSame(
            ['Unknown argument "wdith" for Twig function "image".', 'Unknown argument "size" for Twig filter "shorten".'],
            array_column($unknown ?? [], 'message'),
        );
        self::assertSame([], $diagnostics("{{ image(name: 'a', width: 3, lazy: true) }}"));
        self::assertSame(
            ['Unknown argument "wdith" for Twig function "attribute_image".', 'Unknown argument "size" for Twig filter "attribute_shorten".'],
            array_column($diagnostics("{{ attribute_image(name: 'a', wdith: 3) }}\n{{ text|attribute_shorten(size: 5) }}\n") ?? [], 'message'),
        );
        self::assertSame([], $diagnostics("{{ image(name: 'x, wdith: 3') }}"));
        self::assertSame([], $diagnostics('{{ object.image(wdith: 3) }}'));
        self::assertSame([], $diagnostics("{{ attrs('div', data_test: 'x') }}"));
        self::assertSame([], $diagnostics("{{ attribute_variadic(name: 'a', unknown: 1) }}"));
        self::assertSame([], $diagnostics('{{ dynamic_image(unknown: 1) }}'));
        self::assertSame([], $diagnostics("{{ image({name: 'a'}) }}"));
        self::assertSame([], $diagnostics("{{ image(name ? 'a' : 'b') }}"));
        self::assertSame([], $diagnostics('{{ unknown_callable(anything: 1) }}'));
        self::assertSame([], $diagnostics('{# {{ image(wdith: 3) }} #}'));
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
