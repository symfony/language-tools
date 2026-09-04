<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\ProjectDocumentReader;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\Twig\TwigCallableDeclaration;
use Symfony\Lsp\Feature\Twig\TwigCallableDeclarationExtractor;
use Symfony\Lsp\Feature\Twig\TwigCallableIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigCallableKind;
use Symfony\Lsp\Feature\Twig\TwigCallableMethodResolver;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;

final class TwigCallableMethodResolverTest extends TestCase
{
    public function testUsesIndexedMethodsWhenResolvingRequestParameters(): void
    {
        $uri = 'file:///workspace/src/Twig/MediaExtension.php';
        $source = <<<'PHP'
            <?php
            namespace App\Twig;

            use Twig\TwigFilter;
            use Twig\TwigFunction;

            final class MediaExtension
            {
                public function getFilters(): array
                {
                    return [new TwigFilter('shorten', [self::class, 'shorten'])];
                }

                public function getFunctions(): array
                {
                    return [
                        new TwigFunction('image', [self::class, 'render']),
                        new TwigFunction('attrs', [self::class, 'attrs']),
                        new TwigFunction('dynamic', [self::class, 'dynamic']),
                        new TwigFunction('nullable', [self::class, 'nullable']),
                    ];
                }

                public function render(\Twig\Environment $environment, array $context, string $name, int $width = 200): string
                {
                    return $name;
                }

                public function shorten(string $value, int $length = 30): string
                {
                    return $value;
                }

                public function attrs(string $tag, array $arguments): string
                {
                    return $tag;
                }

                public function dynamic(\Twig\Environment|\stdClass $environment, array $context, string $name = "prefix {$phantom}", mixed ...$options): string
                {
                    return $name;
                }

                public function nullable(\Twig\Environment|null $environment, string $name): string
                {
                    return $name;
                }
            }
            PHP;
        $project = new Project('/workspace', 'file:///workspace');
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $source));
        $parser = new TolerantPhpParser(new Parser());
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $classIndexes->forProject($project)->replace(new DependencyInjectionSourceFacts(
            $uri,
            classes: (new PhpClassDeclarationExtractor(new PositionConverter(), $parser))->extract($uri, $source),
        ));
        $countingParser = new class($parser) implements PhpParserInterface {
            public int $calls = 0;

            public function __construct(private readonly PhpParserInterface $parser)
            {
            }

            public function parse(string $source): PhpDocument
            {
                ++$this->calls;

                return $this->parser->parse($source);
            }
        };
        $callableIndexes = new TwigCallableIndexRegistry();
        $callableIndexes->forProject($project)->replace((new TwigCallableDeclarationExtractor(new PositionConverter(), $parser))->extract(new SourceDocument($uri, 'php', $source)));
        $resolver = new TwigCallableMethodResolver(
            $classIndexes,
            new ProjectDocumentReader($documents, new ProjectPathResolver(new UriToPathConverter())),
            $countingParser,
            $callableIndexes,
        );
        $range = new Range(new Position(0, 0), new Position(0, 1));
        $image = new TwigCallableDeclaration(
            TwigCallableKind::Function,
            'image',
            $uri,
            $range,
            '\\App\Twig\MediaExtension',
            'render',
            needsEnvironment: true,
            needsContext: true,
        );
        $shorten = new TwigCallableDeclaration(
            TwigCallableKind::Filter,
            'shorten',
            $uri,
            $range,
            'App\Twig\MediaExtension',
            'shorten',
        );
        $dynamicImage = new TwigCallableDeclaration(
            TwigCallableKind::Function,
            'dynamic_image',
            $uri,
            $range,
            'App\Twig\MediaExtension',
            'render',
            optionsKnown: false,
        );
        $attrs = new TwigCallableDeclaration(
            TwigCallableKind::Function,
            'attrs',
            $uri,
            $range,
            'App\Twig\MediaExtension',
            'attrs',
            variadic: true,
        );
        $dynamic = new TwigCallableDeclaration(
            TwigCallableKind::Function,
            'dynamic',
            $uri,
            $range,
            'App\Twig\MediaExtension',
            'dynamic',
            optionsKnown: false,
        );
        $nullable = new TwigCallableDeclaration(
            TwigCallableKind::Function,
            'nullable',
            $uri,
            $range,
            'App\Twig\MediaExtension',
            'nullable',
            optionsKnown: false,
        );

        $parameters = $resolver->parameters($project, [
            'image' => ['kind' => TwigCallableKind::Function, 'declarations' => [$image, $image]],
            'shorten' => ['kind' => TwigCallableKind::Filter, 'declarations' => [$shorten]],
            'dynamic_image' => ['kind' => TwigCallableKind::Function, 'declarations' => [$dynamicImage]],
            'attrs' => ['kind' => TwigCallableKind::Function, 'declarations' => [$attrs]],
            'dynamic' => ['kind' => TwigCallableKind::Function, 'declarations' => [$dynamic]],
            'nullable' => ['kind' => TwigCallableKind::Function, 'declarations' => [$nullable]],
        ]);

        self::assertSame(0, $countingParser->calls);
        self::assertSame(['name', 'width'], $parameters['image']->nameable);
        self::assertFalse($parameters['image']->variadic);
        self::assertTrue($parameters['image']->reliable);
        self::assertSame(['length'], $parameters['shorten']->nameable);
        self::assertSame(['name', 'width'], $parameters['dynamic_image']->nameable);
        self::assertFalse($parameters['dynamic_image']->variadic);
        self::assertFalse($parameters['dynamic_image']->reliable);
        self::assertSame(['tag'], $parameters['attrs']->nameable);
        self::assertTrue($parameters['attrs']->variadic);
        self::assertTrue($parameters['attrs']->reliable);
        self::assertSame(['environment', 'context', 'name'], $parameters['dynamic']->nameable);
        self::assertTrue($parameters['dynamic']->variadic);
        self::assertFalse($parameters['dynamic']->reliable);
        self::assertSame(['name'], $parameters['nullable']->nameable);
        self::assertFalse($parameters['nullable']->reliable);
        $methods = $resolver->resolve($project, [$image]);
        self::assertSame(1, $countingParser->calls);
        self::assertCount(1, $methods);
        self::assertSame($uri, $methods[0]->uri);
        self::assertSame($source, $methods[0]->source);
        self::assertSame('render', $methods[0]->declaration->name);
        self::assertTrue($methods[0]->reliable);
    }

    public function testMarksParametersFromPartiallyParsedMethodsAsUnreliable(): void
    {
        $uri = 'file:///workspace/src/Twig/MediaExtension.php';
        $source = <<<'PHP'
            <?php
            namespace App\Twig;

            final class MediaExtension
            {
                public function render(string $name, int $width = 200
            PHP;
        $project = new Project('/workspace', 'file:///workspace');
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $source));
        $parser = new TolerantPhpParser(new Parser());
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $classIndexes->forProject($project)->replace(new DependencyInjectionSourceFacts(
            $uri,
            classes: (new PhpClassDeclarationExtractor(new PositionConverter(), $parser))->extract($uri, $source),
        ));
        $callableIndexes = new TwigCallableIndexRegistry();
        $callableIndexes->forProject($project)->replace((new TwigCallableDeclarationExtractor(new PositionConverter(), $parser))->extract(new SourceDocument($uri, 'php', $source)));
        $resolver = new TwigCallableMethodResolver(
            $classIndexes,
            new ProjectDocumentReader($documents, new ProjectPathResolver(new UriToPathConverter())),
            $parser,
            $callableIndexes,
        );
        $declaration = new TwigCallableDeclaration(
            TwigCallableKind::Function,
            'image',
            $uri,
            new Range(new Position(0, 0), new Position(0, 1)),
            'App\Twig\MediaExtension',
            'render',
        );

        $parameters = $resolver->parameters($project, [
            'image' => ['kind' => TwigCallableKind::Function, 'declarations' => [$declaration]],
        ]);
        $methods = $resolver->resolve($project, [$declaration]);

        self::assertSame(['name', 'width'], $parameters['image']->all);
        self::assertFalse($parameters['image']->reliable);
        self::assertCount(1, $methods);
        self::assertFalse($methods[0]->reliable);
    }
}
