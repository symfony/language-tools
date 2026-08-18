<?php

namespace Symfony\Lsp\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class DiagnosticProviderRegistryTest extends TestCase
{
    public function testPublishesProviderDiagnosticsForProjectDocuments(): void
    {
        [$registry, $client] = $this->registry('file:///workspace/templates/page.html.twig');

        $registry->publish(['textDocument' => ['uri' => 'file:///workspace/templates/page.html.twig']]);

        self::assertCount(1, $client->notifications);
        $diagnostics = $client->notifications[0]['params']['diagnostics'];
        self::assertIsArray($diagnostics);
        self::assertSame(['stub'], array_column($diagnostics, 'code'));
    }

    public function testSuppressesDiagnosticsInDependencyOwnedDocuments(): void
    {
        foreach ([
            'file:///workspace/vendor/acme/bundle/templates/alert.html.twig',
            'file:///workspace/node_modules/lib/index.js',
            'file:///workspace/var/cache/dev/template.php',
        ] as $uri) {
            [$registry, $client] = $this->registry($uri);

            $registry->publish(['textDocument' => ['uri' => $uri]]);

            self::assertCount(1, $client->notifications, $uri);
            self::assertSame([], $client->notifications[0]['params']['diagnostics'], $uri);
        }
    }

    /** @return array{DiagnosticProviderRegistry, CollectingClient} */
    private function registry(string $uri): array
    {
        $client = new CollectingClient();
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'twig', 1, ''));
        $projects = new ProjectRegistry();
        $projects->replace([new Project('/workspace', 'file:///workspace', '^8.0')]);
        $provider = new class implements DiagnosticProviderInterface {
            public function diagnostics(array $params): ?array
            {
                if (!\is_array($params['textDocument'] ?? null)) {
                    return null;
                }

                return [[
                    'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]],
                    'severity' => 1,
                    'source' => 'symfony',
                    'code' => 'stub',
                    'message' => 'Stub diagnostic.',
                ]];
            }
        };

        return [new DiagnosticProviderRegistry(
            $client,
            $documents,
            $projects,
            new ProjectPathResolver(new UriToPathConverter()),
            [$provider],
        ), $client];
    }
}

final class CollectingClient implements ClientInterface
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
