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
use Symfony\Lsp\Feature\Twig\TwigCallableKind;
use Symfony\Lsp\Feature\Twig\TwigCallableMethodResolver;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;

final class TwigCallableMethodResolverTest extends TestCase
{
    public function testParsesEachCallableSourceOnceWhenResolvingRequestParameters(): void
    {
        $uri = 'file:///workspace/src/Twig/MediaExtension.php';
        $source = <<<'PHP'
            <?php
            namespace App\Twig;

            final class MediaExtension
            {
                public function render(\Twig\Environment $environment, array $context, string $name, int $width = 200): string
                {
                    return $name;
                }

                public function shorten(string $value, int $length = 30): string
                {
                    return $value;
                }
            }
            PHP;
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
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
        $resolver = new TwigCallableMethodResolver(
            $classIndexes,
            new ProjectDocumentReader($documents, new ProjectPathResolver(new UriToPathConverter())),
            $countingParser,
        );
        $range = new Range(new Position(0, 0), new Position(0, 1));
        $image = new TwigCallableDeclaration(
            TwigCallableKind::Function,
            'image',
            $uri,
            $range,
            'App\Twig\MediaExtension',
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

        $parameters = $resolver->parameters($project, [
            'image' => ['kind' => TwigCallableKind::Function, 'declarations' => [$image, $image]],
            'shorten' => ['kind' => TwigCallableKind::Filter, 'declarations' => [$shorten]],
        ]);

        self::assertSame(1, $countingParser->calls);
        self::assertSame(['name', 'width'], $parameters['image']->nameable);
        self::assertSame(['length'], $parameters['shorten']->nameable);
        $methods = $resolver->resolve($project, [$image]);
        self::assertCount(1, $methods);
        self::assertSame($uri, $methods[0]->uri);
        self::assertSame($source, $methods[0]->source);
        self::assertSame('render', $methods[0]->declaration->name);
    }
}
