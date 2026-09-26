<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\RouteReference;
use Symfony\Lsp\Feature\Route\RouteRenameHandler;
use Symfony\Lsp\Feature\Route\RouteSourceFacts;
use Symfony\Lsp\Feature\Route\RouteSourceIndexRegistry;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\RenameRequest;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class RouteRenameHandlerTest extends TestCase
{
    public function testPreparesAndRenamesStaticApplicationReferences(): void
    {
        [$handler, $request] = $this->handler();

        self::assertSame([
            'range' => [
                'start' => ['line' => 5, 'character' => 28],
                'end' => ['line' => 5, 'character' => 40],
            ],
            'placeholder' => 'article_show',
        ], $handler->prepare($request));

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
        ], $handler->rename(new RenameRequest($request, 'article_display')));
    }

    public function testRenamesFromYamlDeclaration(): void
    {
        $uri = 'file:///workspace/config/routes.yaml';
        $text = <<<'YAML'
            article_show:
                path: /article/{id}
                controller: App\Controller\ArticleController::show
            YAML;
        $kit = (new ProjectTestKit())->open($uri, $text);
        $sourceIndexes = $kit->get(RouteSourceIndexRegistry::class)->forProject($kit->project());
        $consumerUri = 'file:///workspace/src/ConsumerController.php';
        $sourceIndexes->replace(
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
        $kit->runtime('routes', ['complete' => true, 'items' => [['name' => 'article_show', 'path' => '/article/{id}']]]);
        $handler = $kit->get(RouteRenameHandler::class);

        $edit = $handler->rename($kit->rename([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => 0, 'character' => 3],
        ], 'article_display'));

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
        [$handler, $request] = $this->handler();

        self::assertNull($handler->rename(new RenameRequest($request, 'homepage')));
    }

    public function testRefusesPreparingRouteDeclaredOutsideTheApplication(): void
    {
        [$handler, $request] = $this->handler('file:///workspace/vendor/acme/src/ArticleController.php');

        self::assertNull($handler->prepare($request));
        self::assertNull($handler->rename(new RenameRequest($request, 'article_display')));
    }

    public function testEmitsOneEditWhenADeclarationAndAReferenceShareARange(): void
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
        $kit = (new ProjectTestKit())->open($uri, $text);
        $sourceIndexes = $kit->get(RouteSourceIndexRegistry::class)->forProject($kit->project());
        $range = new Range(new Position(5, 28), new Position(5, 40));
        $sourceIndexes->replace(new RouteSourceFacts(
            $uri,
            [new RouteDeclaration('article_show', $uri, $range)],
            [new RouteReference('article_show', $uri, $range)],
        ));
        $kit->runtime('routes', ['complete' => true, 'items' => [['name' => 'article_show', 'path' => '/article/{id}']]]);
        $handler = $kit->get(RouteRenameHandler::class);

        $edit = $handler->rename($kit->rename([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => 5, 'character' => 31],
        ], 'article_display'));

        self::assertIsArray($edit);
        self::assertSame([[
            'textDocument' => ['uri' => $uri, 'version' => null],
            'edits' => [[
                'range' => [
                    'start' => ['line' => 5, 'character' => 28],
                    'end' => ['line' => 5, 'character' => 40],
                ],
                'newText' => 'article_display',
                'annotationId' => 'routeRename',
            ]],
        ]], $edit['documentChanges']);
    }

    /** @return array{RouteRenameHandler, PositionedRequest} */
    private function handler(string $declarationUri = 'file:///workspace/src/ArticleController.php'): array
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
        $kit = (new ProjectTestKit())->open($uri, $text);
        $sourceIndexes = $kit->get(RouteSourceIndexRegistry::class)->forProject($kit->project());
        $vendorUri = 'file:///workspace/vendor/acme/Consumer.php';
        $sourceIndexes->replace(
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
        $kit->runtime('routes', ['complete' => true, 'items' => [['name' => 'article_show', 'path' => '/article/{id}'], ['name' => 'homepage', 'path' => '/']]]);

        return [
            $kit->get(RouteRenameHandler::class),
            $kit->positioned([
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => 5, 'character' => 31],
            ]),
        ];
    }
}
