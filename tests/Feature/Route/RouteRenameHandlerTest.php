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
use Symfony\Lsp\Feature\Route\PhpRouteDeclarationExtractor;
use Symfony\Lsp\Feature\Route\Route;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\RouteDeclarationIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\RouteReferenceIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferenceLocation;
use Symfony\Lsp\Feature\Route\RouteRenameHandler;
use Symfony\Lsp\Feature\Route\RouteSymbolResolver;
use Symfony\Lsp\Feature\Route\TwigRouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\YamlRouteDeclarationExtractor;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

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
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $declarations = new RouteDeclarationIndexRegistry();
        $declarations->forProject($project)->replace(new RouteDeclaration(
            'article_show',
            $uri,
            new Range(new Position(0, 0), new Position(0, 12)),
        ));
        $references = new RouteReferenceIndexRegistry();
        $references->forProject($project)->replace(new RouteReferenceLocation(
            'article_show',
            'file:///workspace/src/ConsumerController.php',
            new Range(new Position(5, 28), new Position(5, 40)),
        ));
        $routes = new RouteIndexRegistry();
        $routes->forProject($project)->replace(new Route('article_show', '/article/{id}', [], [], null, null));
        $positionConverter = new PositionConverter();
        $handler = new RouteRenameHandler(
            new DocumentContextResolver($documents, $projects),
            new RouteSymbolResolver(
                $positionConverter,
                new RouteReferenceExtractor($positionConverter),
                new TwigRouteReferenceExtractor($positionConverter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
                new PhpRouteDeclarationExtractor($positionConverter, new TolerantPhpParser(new Parser())),
                new YamlRouteDeclarationExtractor($positionConverter),
            ),
            $references,
            $declarations,
            $routes,
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
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $declarations = new RouteDeclarationIndexRegistry();
        $declarations->forProject($project)->replace(new RouteDeclaration(
            'article_show',
            'file:///workspace/src/ArticleController.php',
            new Range(new Position(10, 20), new Position(10, 32)),
        ));
        $references = new RouteReferenceIndexRegistry();
        $references->forProject($project)->replace(new RouteReferenceLocation(
            'article_show',
            $uri,
            new Range(new Position(5, 28), new Position(5, 40)),
        ));
        $routes = new RouteIndexRegistry();
        $routes->forProject($project)->replace(
            new Route('article_show', '/article/{id}', [], [], null, null),
            new Route('homepage', '/', [], [], null, null),
        );
        $positionConverter = new PositionConverter();
        $handler = new RouteRenameHandler(
            new DocumentContextResolver($documents, $projects),
            new RouteSymbolResolver(
                $positionConverter,
                new RouteReferenceExtractor($positionConverter),
                new TwigRouteReferenceExtractor($positionConverter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
                new PhpRouteDeclarationExtractor($positionConverter, new TolerantPhpParser(new Parser())),
                new YamlRouteDeclarationExtractor($positionConverter),
            ),
            $references,
            $declarations,
            $routes,
        );

        return [$handler, [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => 5, 'character' => 31],
        ]];
    }
}
