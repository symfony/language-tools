<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DiagnosticProviderRegistry;
use Symfony\Lsp\Feature\Route\Route;
use Symfony\Lsp\Feature\Route\RouteCodeActionProvider;
use Symfony\Lsp\Feature\Route\RouteDiagnosticPublisher;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\TwigRouteReferenceExtractor;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class RouteDiagnosticPublisherTest extends TestCase
{
    public function testPublishesUnknownRouteDiagnosticsOnlyForSymfonyReceivers(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            class ArticleController extends AbstractController
            {
                public function show(): void
                {
                    $this->generateUrl('missing_route');
                    $this->generateUrl('article_show');
                    $unknown->generateUrl('also_missing');
                }
            }
            PHP;
        [$publisher, $client] = $this->publisher($uri, $text);

        $publisher->publish(['textDocument' => ['uri' => $uri]]);

        self::assertSame('textDocument/publishDiagnostics', $client->notifications[0]['method']);
        self::assertSame([[
            'range' => [
                'start' => ['line' => 5, 'character' => 28],
                'end' => ['line' => 5, 'character' => 41],
            ],
            'severity' => 1,
            'source' => 'symfony',
            'code' => 'route.not_found',
            'message' => 'Route "missing_route" does not exist in the selected environment.',
        ]], $client->notifications[0]['params']['diagnostics']);
    }

    public function testDiagnosesMissingRequiredRouteParameters(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            class ArticleController extends AbstractController
            {
                public function show(): void
                {
                    $this->generateUrl('article_show');
                }
            }
            PHP;
        [$publisher, $client] = $this->publisher($uri, $text, new Route(
            'article_show',
            '/{locale}/article/{id}',
            ['GET'],
            [],
            null,
            null,
            ['locale'],
        ));

        $publisher->publish(['textDocument' => ['uri' => $uri]]);

        $diagnostics = $client->notifications[0]['params']['diagnostics'];
        self::assertIsArray($diagnostics);
        self::assertIsArray($diagnostics[0]);
        self::assertSame('route.missing_parameters', $diagnostics[0]['code']);
        self::assertSame(
            'Route "article_show" requires parameter "id".',
            $diagnostics[0]['message'],
        );
    }

    public function testAddsMissingRouteParameters(): void
    {
        $cases = [
            ['php', 'file:///workspace/src/Controller.php', <<<'PHP'
                <?php
                class ArticleController extends AbstractController
                {
                    public function show(): void
                    {
                        $this->generateUrl('article_show');
                    }
                }
                PHP, ", ['id' => null]"],
            ['twig', 'file:///workspace/templates/page.html.twig', "{{ path('article_show', {foo: 1}) }}", "'id': null, "],
        ];
        foreach ($cases as [$languageId, $uri, $text, $expectedText]) {
            $documents = new DocumentStore();
            $documents->open(new Document($uri, $languageId, 1, $text));
            $projects = new ProjectRegistry();
            $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
            $indexes = new RouteIndexRegistry();
            $indexes->forProject($project)->replace(new Route('article_show', '/article/{id}', ['GET'], [], null, null));
            $converter = new PositionConverter();
            $phpExtractor = new RouteReferenceExtractor($converter);
            $twigExtractor = new TwigRouteReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())));
            $diagnosticProvider = new RouteDiagnosticPublisher($documents, $projects, $indexes, $phpExtractor, $twigExtractor);
            $diagnostics = $diagnosticProvider->diagnostics(['textDocument' => ['uri' => $uri]]);
            self::assertIsArray($diagnostics);
            $provider = new RouteCodeActionProvider($documents, $projects, $converter, $indexes, $phpExtractor, $twigExtractor);

            $actions = $provider->actions([
                'textDocument' => ['uri' => $uri],
                'range' => $diagnostics[0]['range'],
                'context' => ['diagnostics' => $diagnostics],
            ]);

            self::assertIsArray($actions);
            self::assertCount(1, $actions);
            $action = $actions[0];
            self::assertSame('Add missing route parameter', $action['title'] ?? null);
            self::assertIsArray($action['edit'] ?? null);
            self::assertIsArray($action['edit']['documentChanges'] ?? null);
            self::assertIsArray($action['edit']['documentChanges'][0]);
            self::assertIsArray($action['edit']['documentChanges'][0]['edits'] ?? null);
            self::assertIsArray($action['edit']['documentChanges'][0]['edits'][0]);
            self::assertSame($expectedText, $action['edit']['documentChanges'][0]['edits'][0]['newText'] ?? null);
        }
    }

    public function testDoesNotDiagnoseDynamicRouteParameters(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            class ArticleController extends AbstractController
            {
                public function show(array $parameters): void
                {
                    $this->generateUrl('article_show', $parameters);
                }
            }
            PHP;
        [$publisher, $client] = $this->publisher($uri, $text);

        $publisher->publish(['textDocument' => ['uri' => $uri]]);

        self::assertSame([], $client->notifications[0]['params']['diagnostics']);
    }

    public function testDiagnosesUnknownRoutesInTwig(): void
    {
        $uri = 'file:///workspace/templates/navigation.html.twig';
        [$publisher, $client] = $this->publisher(
            $uri,
            "{{ path('missing_route') }}",
            languageId: 'twig',
        );

        $publisher->publish(['textDocument' => ['uri' => $uri]]);

        $diagnostics = $client->notifications[0]['params']['diagnostics'];
        self::assertIsArray($diagnostics);
        self::assertIsArray($diagnostics[0]);
        self::assertSame('route.not_found', $diagnostics[0]['code']);
    }

    public function testDoesNotDiagnoseBeforeCompleteRuntimeMetadataIsAvailable(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $client = new DiagnosticClient();
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, <<<'PHP'
            <?php
            class ArticleController extends AbstractController
            {
                public function show(): void
                {
                    $this->generateUrl('missing_route');
                }
            }
            PHP));
        $projects = new ProjectRegistry();
        $projects->replace([new Project('/workspace', 'file:///workspace', '^8.0')]);
        $positionConverter = new PositionConverter();
        $publisher = new DiagnosticProviderRegistry(
            $client,
            $documents,
            $projects,
            new RouteDiagnosticPublisher(
                $documents,
                $projects,
                new RouteIndexRegistry(),
                new RouteReferenceExtractor($positionConverter),
                new TwigRouteReferenceExtractor($positionConverter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
            ),
        );

        $publisher->publish(['textDocument' => ['uri' => $uri]]);

        self::assertSame([], $client->notifications);
    }

    public function testRepublishesOpenDocumentDiagnosticsAfterRuntimeRefresh(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        [$publisher, $client, $project] = $this->publisher($uri, <<<'PHP'
            <?php
            class ArticleController extends AbstractController
            {
                public function show(): void
                {
                    $this->generateUrl('missing_route');
                }
            }
            PHP);

        $publisher->refreshed($project);

        self::assertCount(1, $client->notifications);
        self::assertSame('textDocument/publishDiagnostics', $client->notifications[0]['method']);
    }

    public function testClearsDiagnosticsWhenDocumentCloses(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        [$publisher, $client] = $this->publisher($uri, '<?php');

        $publisher->clear(['textDocument' => ['uri' => $uri]]);

        self::assertSame([
            'uri' => $uri,
            'diagnostics' => [],
        ], $client->notifications[0]['params']);
    }

    /**
     * @return array{DiagnosticProviderRegistry, DiagnosticClient, Project}
     */
    private function publisher(
        string $uri,
        string $text,
        ?Route $route = null,
        string $languageId = 'php',
    ): array {
        $client = new DiagnosticClient();
        $documents = new DocumentStore();
        $documents->open(new Document($uri, $languageId, 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $routeIndexes = new RouteIndexRegistry();
        $routeIndexes->forProject($project)->replace(
            $route ?? new Route('article_show', '/article', ['GET'], [], null, null),
        );
        $positionConverter = new PositionConverter();

        return [
            new DiagnosticProviderRegistry(
                $client,
                $documents,
                $projects,
                new RouteDiagnosticPublisher(
                    $documents,
                    $projects,
                    $routeIndexes,
                    new RouteReferenceExtractor($positionConverter),
                    new TwigRouteReferenceExtractor($positionConverter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
                ),
            ),
            $client,
            $project,
        ];
    }
}

final class DiagnosticClient implements ClientInterface
{
    /** @var list<array{method: string, params: array<array-key, mixed>}> */
    public array $notifications = [];

    public function request(string $method, array $params): mixed
    {
        return null;
    }

    public function notify(string $method, array $params): void
    {
        $this->notifications[] = ['method' => $method, 'params' => $params];
    }
}
