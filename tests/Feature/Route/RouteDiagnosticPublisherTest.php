<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\Route;
use Symfony\Lsp\Feature\Route\RouteDiagnosticPublisher;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferenceExtractor;
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
     * @return array{RouteDiagnosticPublisher, DiagnosticClient}
     */
    private function publisher(string $uri, string $text): array
    {
        $client = new DiagnosticClient();
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $routeIndexes = new RouteIndexRegistry();
        $routeIndexes->forProject($project)->replace(
            new Route('article_show', '/article/{id}', ['GET'], [], null, null),
        );
        $positionConverter = new PositionConverter();

        return [
            new RouteDiagnosticPublisher(
                $client,
                $documents,
                $projects,
                $routeIndexes,
                new RouteReferenceExtractor($positionConverter),
            ),
            $client,
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
