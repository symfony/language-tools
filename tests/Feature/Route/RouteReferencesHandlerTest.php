<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\Route\PhpRouteDeclarationExtractor;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\RouteDeclarationIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\RouteReferenceIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferenceLocation;
use Symfony\Lsp\Feature\Route\RouteReferencesHandler;
use Symfony\Lsp\Feature\Route\RouteSymbolResolver;
use Symfony\Lsp\Feature\Route\TwigRouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\YamlRouteDeclarationExtractor;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteReferencesHandlerTest extends TestCase
{
    public function testFindsReferencesFromRouteDeclaration(): void
    {
        $uri = 'file:///workspace/src/ArticleController.php';
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Routing\Attribute\Route;
            #[Route('/article', name: 'article_list')]
            final class ArticleController {}
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $declarations = new RouteDeclarationIndexRegistry();
        $declarations->forProject($project)->replace(new RouteDeclaration(
            'article_list',
            $uri,
            new Range(new Position(2, 32), new Position(2, 44)),
        ));
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $positionConverter = new PositionConverter();
        $classExtractor = new PhpClassDeclarationExtractor($positionConverter, new TolerantPhpParser(new Parser()));
        $baseUri = 'file:///workspace/src/BaseController.php';
        $base = '<?php namespace App\\Controller; use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController; abstract class BaseController extends AbstractController {}';
        $consumerUri = 'file:///workspace/src/Navigation.php';
        $consumer = '<?php namespace App\\Controller; final class DemoController extends BaseController {}';
        $classIndexes->forProject($project)->replace(
            new DependencyInjectionSourceFacts($baseUri, classes: $classExtractor->extract($baseUri, $base)),
            new DependencyInjectionSourceFacts($consumerUri, classes: $classExtractor->extract($consumerUri, $consumer)),
        );
        $references = new RouteReferenceIndexRegistry($classIndexes);
        $references->forProject($project)->replace(new RouteReferenceLocation(
            'article_list',
            $consumerUri,
            new Range(new Position(12, 20), new Position(12, 32)),
            'App\\Controller\\DemoController',
        ));
        $handler = new RouteReferencesHandler(
            new DocumentContextResolver($documents, $projects),
            new LspProtocolMapper(),
            new RouteSymbolResolver(
                $positionConverter,
                new RouteReferenceExtractor($positionConverter, new TolerantPhpParser(new Parser()), new QuotedArgumentMatcher($positionConverter), new PhpCommentParser()),
                new TwigRouteReferenceExtractor($positionConverter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser())),
                new PhpRouteDeclarationExtractor($positionConverter, new TolerantPhpParser(new Parser())),
                new YamlRouteDeclarationExtractor($positionConverter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
                new UriToPathConverter(),
                $classIndexes,
            ),
            $references,
            $declarations,
        );

        self::assertSame([
            [
                'uri' => $consumerUri,
                'range' => [
                    'start' => ['line' => 12, 'character' => 20],
                    'end' => ['line' => 12, 'character' => 32],
                ],
            ],
            [
                'uri' => $uri,
                'range' => [
                    'start' => ['line' => 2, 'character' => 32],
                    'end' => ['line' => 2, 'character' => 44],
                ],
            ],
        ], $handler->references([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => 2, 'character' => 35],
            'context' => ['includeDeclaration' => true],
        ]));
    }
}
