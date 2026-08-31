<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Feature\DiagnosticCollector;
use Symfony\Lsp\Feature\DiagnosticProviderRegistry;
use Symfony\Lsp\Feature\DiagnosticSuppressor;
use Symfony\Lsp\Feature\Route\Route;
use Symfony\Lsp\Feature\Route\RouteCodeActionProvider;
use Symfony\Lsp\Feature\Route\RouteDiagnosticPublisher;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\Route\TwigRouteReferenceExtractor;
use Symfony\Lsp\Feature\Twig\TemplateDeclaration;
use Symfony\Lsp\Feature\Twig\TemplateIndexRegistry;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

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

    public function testHandlesCanonicalAndConcreteInternationalizedRouteNames(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        [$publisher, $client] = $this->publisher(
            $uri,
            <<<'PHP'
                <?php
                class HomeController extends AbstractController
                {
                    public function index(): void
                    {
                        $this->generateUrl('app_home');
                        $this->generateUrl('app_home.fr', ['french' => 'bonjour']);
                    }
                }
                PHP,
            [
                new Route(
                    name: 'app_home.en',
                    path: '/en/{english}',
                    methods: ['GET'],
                    schemes: [],
                    host: null,
                    controller: null,
                    canonicalName: 'app_home',
                ),
                new Route(
                    name: 'app_home.fr',
                    path: '/fr/{french}',
                    methods: ['GET'],
                    schemes: [],
                    host: null,
                    controller: null,
                    canonicalName: 'app_home',
                ),
            ],
        );

        $publisher->publish(['textDocument' => ['uri' => $uri]]);

        self::assertSame([], $client->notifications[0]['params']['diagnostics']);
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

    public function testDiagnosesOnlyGenuinelyMissingTwigShorthandParameters(): void
    {
        $uri = 'file:///workspace/templates/page.html.twig';
        [$publisher, $client] = $this->publisher($uri, <<<'TWIG'
            {{ path('complete', { version }) }}
            {{ path('incomplete', { year }) }}
            {{ path('dynamic_argument', parameters) }}
            {{ path('dynamic_key', {(parameter): value}) }}
            {{ path('dynamic_spread', { year, ...parameters}) }}
            TWIG, [
            new Route('complete', '/{version}', [], [], null, null),
            new Route('incomplete', '/{year}/{month}', [], [], null, null),
            new Route('dynamic_argument', '/{id}', [], [], null, null),
            new Route('dynamic_key', '/{id}', [], [], null, null),
            new Route('dynamic_spread', '/{year}/{month}', [], [], null, null),
        ], 'twig');

        $publisher->publish(['textDocument' => ['uri' => $uri]]);

        $diagnostics = $client->notifications[0]['params']['diagnostics'];
        self::assertIsArray($diagnostics);
        self::assertCount(1, $diagnostics);
        self::assertIsArray($diagnostics[0]);
        self::assertSame('route.missing_parameters', $diagnostics[0]['code'] ?? null);
        self::assertSame('Route "incomplete" requires parameter "month".', $diagnostics[0]['message'] ?? null);
    }

    public function testAcceptsRouteParametersProvidedByTheRequestContext(): void
    {
        $uri = 'file:///workspace/templates/page.html.twig';
        [$publisher, $client] = $this->publisher($uri, <<<'TWIG'
            {{ path('complete', { id: article.id }) }}
            {{ path('incomplete') }}
            TWIG, [
            new Route('complete', '/{_locale}/article/{id}', [], [], null, null),
            new Route('incomplete', '/{_locale}/article/{id}', [], [], null, null),
        ], 'twig', contextParameters: ['_locale']);

        $publisher->publish(['textDocument' => ['uri' => $uri]]);

        $diagnostics = $client->notifications[0]['params']['diagnostics'];
        self::assertIsArray($diagnostics);
        self::assertCount(1, $diagnostics);
        self::assertIsArray($diagnostics[0]);
        self::assertSame('route.missing_parameters', $diagnostics[0]['code'] ?? null);
        self::assertSame('Route "incomplete" requires parameter "id".', $diagnostics[0]['message'] ?? null);
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
            $indexes->forProject($project)->replaceRuntime(
                [],
                ['_locale'],
                new Route('article_show', '/{_locale}/article/{id}', ['GET'], [], null, null),
            );
            $converter = new PositionConverter();
            $classIndexes = new DependencyInjectionSourceIndexRegistry();
            $phpExtractor = RouteReferenceExtractorFactory::create($converter);
            $twigExtractor = new TwigRouteReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser()));
            $templateIndexes = new TemplateIndexRegistry();
            $templateIndexes->forProject($project)->replaceRuntime(true, new TemplateDeclaration('page.html.twig', $uri, new Range(new Position(0, 0), new Position(0, 0))));
            $diagnosticProvider = new RouteDiagnosticPublisher(new DocumentContextResolver($documents, $projects), new LspProtocolMapper(), $indexes, $classIndexes, $phpExtractor, $twigExtractor, $templateIndexes);
            $diagnostics = $diagnosticProvider->diagnostics(['textDocument' => ['uri' => $uri]]);
            self::assertIsArray($diagnostics);
            $provider = new RouteCodeActionProvider(new DocumentContextResolver($documents, $projects), $converter, new LspProtocolMapper(), $indexes, $classIndexes, $phpExtractor, $twigExtractor, new ProjectPathResolver(new UriToPathConverter()));

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

    public function testDoesNotDiagnoseTwigFilesOutsideRuntimeLoaderPaths(): void
    {
        $uri = 'file:///workspace/book/design/templates/base.html.twig';
        [$publisher, $client] = $this->publisher(
            $uri,
            "{{ path('missing_route') }}",
            languageId: 'twig',
            runtimeTemplate: false,
        );

        $publisher->publish(['textDocument' => ['uri' => $uri]]);

        self::assertSame([], $client->notifications[0]['params']['diagnostics']);
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
        $uriConverter = new UriToPathConverter();
        $collector = new DiagnosticCollector(
            $documents,
            $projects,
            new ProjectPathResolver($uriConverter),
            new ProjectFileScopeRegistry(),
            $uriConverter,
            $this->suppressor($positionConverter),
            [new RouteDiagnosticPublisher(
                new DocumentContextResolver($documents, $projects),
                new LspProtocolMapper(),
                new RouteIndexRegistry(),
                new DependencyInjectionSourceIndexRegistry(),
                RouteReferenceExtractorFactory::create($positionConverter),
                new TwigRouteReferenceExtractor($positionConverter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser())),
                new TemplateIndexRegistry(),
            )],
        );
        $publisher = new DiagnosticProviderRegistry($client, $documents, $projects, $collector);

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

    private function suppressor(PositionConverter $positions): DiagnosticSuppressor
    {
        return new DiagnosticSuppressor(
            $positions,
            new LspProtocolMapper(),
            new DiagnosticCodeRegistry(),
            new PhpCommentParser(),
            new TwigCommentParser(),
            new YamlCommentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
            new XmlCommentParser(),
        );
    }

    /**
     * @param Route|list<Route>|null $route
     * @param list<string>           $contextParameters
     *
     * @return array{DiagnosticProviderRegistry, DiagnosticClient, Project}
     */
    private function publisher(
        string $uri,
        string $text,
        Route|array|null $route = null,
        string $languageId = 'php',
        bool $runtimeTemplate = true,
        array $contextParameters = [],
    ): array {
        $client = new DiagnosticClient();
        $documents = new DocumentStore();
        $documents->open(new Document($uri, $languageId, 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $routeIndexes = new RouteIndexRegistry();
        $routeIndexes->forProject($project)->replaceRuntime([], $contextParameters, ...(\is_array($route) ? $route : [
            $route ?? new Route('article_show', '/article', ['GET'], [], null, null),
        ]));
        $positionConverter = new PositionConverter();
        $templateIndexes = new TemplateIndexRegistry();
        $templateIndexes->forProject($project)->replaceRuntime(
            true,
            ...('twig' === $languageId && $runtimeTemplate ? [new TemplateDeclaration(basename($uri), $uri, new Range(new Position(0, 0), new Position(0, 0)))] : []),
        );

        $uriConverter = new UriToPathConverter();
        $collector = new DiagnosticCollector(
            $documents,
            $projects,
            new ProjectPathResolver($uriConverter),
            new ProjectFileScopeRegistry(),
            $uriConverter,
            $this->suppressor($positionConverter),
            [new RouteDiagnosticPublisher(
                new DocumentContextResolver($documents, $projects),
                new LspProtocolMapper(),
                $routeIndexes,
                new DependencyInjectionSourceIndexRegistry(),
                RouteReferenceExtractorFactory::create($positionConverter),
                new TwigRouteReferenceExtractor($positionConverter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser())),
                $templateIndexes,
            )],
        );

        return [
            new DiagnosticProviderRegistry($client, $documents, $projects, $collector),
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
