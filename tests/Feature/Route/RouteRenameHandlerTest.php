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
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\Route\PhpRouteDeclarationExtractor;
use Symfony\Lsp\Feature\Route\Route;
use Symfony\Lsp\Feature\Route\RouteControllerClassifier;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReference;
use Symfony\Lsp\Feature\Route\RouteRenameHandler;
use Symfony\Lsp\Feature\Route\RouteSourceFacts;
use Symfony\Lsp\Feature\Route\RouteSourceIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteSymbolResolver;
use Symfony\Lsp\Feature\Route\TwigRouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\YamlRouteDeclarationExtractor;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigArgumentParser;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteRenameHandlerTest extends TestCase
{
    public function testPreparesAndRenamesStaticApplicationReferences(): void
    {
        [$handler, $params] = $this->handler();

        self::assertSame([
            'range' => [
                'start' => ['line' => 5, 'character' => 28],
                'end' => ['line' => 5, 'character' => 40],
            ],
            'placeholder' => 'article_show',
        ], $handler->prepare($params));

        self::assertSame([
            'documentChanges' => [
                [
                    'textDocument' => [
                        'uri' => 'file:///workspace/src/ArticleController.php',
                        'version' => null,
                    ],
                    'edits' => [[
                        'range' => [
                            'start' => ['line' => 10, 'character' => 20],
                            'end' => ['line' => 10, 'character' => 32],
                        ],
                        'newText' => 'article_display',
                        'annotationId' => 'routeRename',
                    ]],
                ],
                [
                    'textDocument' => [
                        'uri' => 'file:///workspace/src/ConsumerController.php',
                        'version' => null,
                    ],
                    'edits' => [[
                        'range' => [
                            'start' => ['line' => 5, 'character' => 28],
                            'end' => ['line' => 5, 'character' => 40],
                        ],
                        'newText' => 'article_display',
                        'annotationId' => 'routeRename',
                    ]],
                ],
            ],
            'changeAnnotations' => [
                'routeRename' => [
                    'label' => 'Rename route "article_show" to "article_display"',
                    'needsConfirmation' => true,
                    'description' => 'Dynamic route references may remain unchanged.',
                ],
            ],
        ], $handler->rename([...$params, 'newName' => 'article_display']));
    }

    public function testRenamesFromYamlDeclaration(): void
    {
        $uri = 'file:///workspace/config/routes.yaml';
        $text = <<<'YAML'
            article_show:
                path: /article/{id}
                controller: App\Controller\ArticleController::show
            YAML;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $sourceIndexes = new RouteSourceIndexRegistry($classIndexes, new RouteControllerClassifier());
        $consumerUri = 'file:///workspace/src/ConsumerController.php';
        $sourceIndexes->forProject($project)->replace(
            new RouteSourceFacts($uri, [new RouteDeclaration(
                'article_show',
                $uri,
                new Range(new Position(0, 0), new Position(0, 12)),
            )], []),
            new RouteSourceFacts($consumerUri, [], [new RouteReference(
                'article_show',
                $consumerUri,
                new Range(new Position(5, 28), new Position(5, 40)),
            )]),
        );
        $routes = new RouteIndexRegistry();
        $routes->forProject($project)->replace(new Route('article_show', '/article/{id}', [], [], null, null));
        $positionConverter = new PositionConverter();
        $handler = new RouteRenameHandler(
            new DocumentContextResolver($documents, $projects),
            new LspProtocolMapper(),
            new RouteSymbolResolver(
                $positionConverter,
                RouteReferenceExtractorFactory::create($positionConverter),
                new TwigRouteReferenceExtractor($positionConverter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser()), new TwigCallArgumentResolver(new TwigArgumentParser())),
                new PhpRouteDeclarationExtractor($positionConverter, new TolerantPhpParser(new Parser())),
                new YamlRouteDeclarationExtractor($positionConverter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
                new UriToPathConverter(),
                $classIndexes,
            ),
            $sourceIndexes,
            $routes,
            new ProjectPathResolver(new UriToPathConverter()),
        );

        $edit = $handler->rename([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => 0, 'character' => 3],
            'newName' => 'article_display',
        ]);

        self::assertIsArray($edit);
        self::assertSame(
            'file:///workspace/config/routes.yaml',
            $edit['documentChanges'][0]['textDocument']['uri'],
        );
        self::assertSame(
            'file:///workspace/src/ConsumerController.php',
            $edit['documentChanges'][1]['textDocument']['uri'],
        );
        self::assertSame('article_display', $edit['documentChanges'][0]['edits'][0]['newText']);
    }

    public function testRejectsExistingRouteName(): void
    {
        [$handler, $params] = $this->handler();

        self::assertNull($handler->rename([...$params, 'newName' => 'homepage']));
    }

    /**
     * @return array{RouteRenameHandler, array{textDocument: array{uri: string}, position: array{line: int, character: int}}}
     */
    private function handler(): array
    {
        $uri = 'file:///workspace/src/ConsumerController.php';
        $text = <<<'PHP'
            <?php
            class ConsumerController extends AbstractController
            {
                public function index(): void
                {
                    $this->generateUrl('article_show');
                }
            }
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $sourceIndexes = new RouteSourceIndexRegistry($classIndexes, new RouteControllerClassifier());
        $declarationUri = 'file:///workspace/src/ArticleController.php';
        $vendorUri = 'file:///workspace/vendor/acme/Consumer.php';
        $sourceIndexes->forProject($project)->replace(
            new RouteSourceFacts($declarationUri, [new RouteDeclaration(
                'article_show',
                $declarationUri,
                new Range(new Position(10, 20), new Position(10, 32)),
            )], []),
            new RouteSourceFacts($uri, [], [new RouteReference(
                'article_show',
                $uri,
                new Range(new Position(5, 28), new Position(5, 40)),
            )]),
            new RouteSourceFacts($vendorUri, [], [new RouteReference(
                'article_show',
                $vendorUri,
                new Range(new Position(5, 28), new Position(5, 40)),
            )]),
        );
        $routes = new RouteIndexRegistry();
        $routes->forProject($project)->replace(
            new Route('article_show', '/article/{id}', [], [], null, null),
            new Route('homepage', '/', [], [], null, null),
        );
        $positionConverter = new PositionConverter();
        $handler = new RouteRenameHandler(
            new DocumentContextResolver($documents, $projects),
            new LspProtocolMapper(),
            new RouteSymbolResolver(
                $positionConverter,
                RouteReferenceExtractorFactory::create($positionConverter),
                new TwigRouteReferenceExtractor($positionConverter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser()), new TwigCallArgumentResolver(new TwigArgumentParser())),
                new PhpRouteDeclarationExtractor($positionConverter, new TolerantPhpParser(new Parser())),
                new YamlRouteDeclarationExtractor($positionConverter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
                new UriToPathConverter(),
                $classIndexes,
            ),
            $sourceIndexes,
            $routes,
            new ProjectPathResolver(new UriToPathConverter()),
        );

        return [$handler, [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => 5, 'character' => 31],
        ]];
    }
}
