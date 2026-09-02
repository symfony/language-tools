<?php

namespace Symfony\Lsp\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DetailedDiagnosticCollection;
use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Feature\DiagnosticCollector;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderRegistry;
use Symfony\Lsp\Feature\DiagnosticSuppressor;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DiagnosticProviderRegistryTest extends TestCase
{
    public function testCollectsAndPublishesProviderDiagnosticsForProjectDocuments(): void
    {
        [$registry, $client, $collector] = $this->registry('file:///workspace/templates/page.html.twig');
        $params = ['textDocument' => ['uri' => 'file:///workspace/templates/page.html.twig']];

        $collected = $collector->collect($params);
        $registry->publish($params);

        self::assertIsArray($collected);
        self::assertSame(['stub'], array_column($collected, 'code'));
        self::assertCount(1, $client->notifications);
        $diagnostics = $client->notifications[0]['params']['diagnostics'];
        self::assertIsArray($diagnostics);
        self::assertSame($collected, $diagnostics);
    }

    public function testDoesNotPublishWhenNoProviderMatches(): void
    {
        [$registry, $client] = $this->registryWithProviders(
            'file:///workspace/templates/page.html.twig',
            new StubDiagnosticProvider(null),
        );

        $registry->publish(['textDocument' => ['uri' => 'file:///workspace/templates/page.html.twig']]);

        self::assertSame([], $client->notifications);
    }

    public function testPublishesAnEmptyListWhenAProviderMatchesWithoutDiagnostics(): void
    {
        [$registry, $client] = $this->registryWithProviders(
            'file:///workspace/templates/page.html.twig',
            new StubDiagnosticProvider([]),
        );

        $registry->publish(['textDocument' => ['uri' => 'file:///workspace/templates/page.html.twig']]);

        self::assertCount(1, $client->notifications);
        self::assertSame([], $client->notifications[0]['params']['diagnostics']);
    }

    public function testDetailedCollectionKeepsSuccessfulProvidersAroundFailures(): void
    {
        [, , $collector] = $this->registryWithProviders(
            'file:///workspace/templates/page.html.twig',
            new StubDiagnosticProvider([$this->diagnostic('first')], 'first-provider'),
            new ThrowingDiagnosticProvider(),
            new MalformedDiagnosticProvider(),
            new StubDiagnosticProvider([$this->diagnostic('third')], 'third-provider'),
        );

        $collection = $collector->collectDetailed(['textDocument' => ['uri' => 'file:///workspace/templates/page.html.twig']]);

        self::assertNotNull($collection);
        self::assertSame(['first-provider', 'third-provider'], array_map(static fn ($diagnostic): string => $diagnostic->provider, $collection->diagnostics));
        self::assertSame(['first', 'third'], array_column(array_map(static fn ($diagnostic): array => $diagnostic->diagnostic, $collection->diagnostics), 'code'));
        self::assertSame(['broken-provider', 'malformed-provider'], array_map(static fn ($failure): string => $failure->provider, $collection->failures));
        self::assertSame('Provider failed.', $collection->failures[0]->error->getMessage());
        self::assertSame('A diagnostic provider returned a non-array diagnostic.', $collection->failures[1]->error->getMessage());
    }

    public function testSuppressesDiagnosticsInSimpleAndDetailedCollection(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $source = "<?php\n// @symfony-lsp-ignore template.not_found\nrender('missing');\n";
        [$registry, $client, $collector] = $this->registryForDocument(
            $uri,
            'php',
            $source,
            [],
            new StubDiagnosticProvider([$this->diagnostic('template.not_found', 2)], 'template'),
        );
        $params = ['textDocument' => ['uri' => $uri]];

        $registry->publish($params);
        $detailed = $collector->collectDetailed($params);

        self::assertSame([], $client->notifications[0]['params']['diagnostics']);
        self::assertInstanceOf(DetailedDiagnosticCollection::class, $detailed);
        self::assertSame([], $detailed->diagnostics);
        self::assertTrue($detailed->matched);
    }

    public function testMergesProviderDiagnosticsInOrder(): void
    {
        [$registry, $client] = $this->registryWithProviders(
            'file:///workspace/templates/page.html.twig',
            new StubDiagnosticProvider(null),
            new StubDiagnosticProvider([$this->diagnostic('second')]),
            new StubDiagnosticProvider([$this->diagnostic('third')]),
        );

        $registry->publish(['textDocument' => ['uri' => 'file:///workspace/templates/page.html.twig']]);

        self::assertCount(1, $client->notifications);
        $diagnostics = $client->notifications[0]['params']['diagnostics'];
        self::assertIsArray($diagnostics);
        self::assertSame(['second', 'third'], array_column($diagnostics, 'code'));
    }

    public function testSuppressesConfiguredPathsUnlessExplicitlyIncluded(): void
    {
        [$registry, $client, $collector] = $this->registryWithScope(
            'file:///workspace/tests/Fixtures/page.html.twig',
            ['tests/Fixtures/**'],
            new StubDiagnosticProvider([$this->diagnostic('stub')]),
        );
        $params = ['textDocument' => ['uri' => 'file:///workspace/tests/Fixtures/page.html.twig']];

        $registry->publish($params);

        self::assertSame([], $client->notifications[0]['params']['diagnostics']);
        self::assertSame(['stub'], array_column($collector->collect($params, true) ?? [], 'code'));
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

    /** @return array{DiagnosticProviderRegistry, CollectingClient, DiagnosticCollector} */
    private function registry(string $uri): array
    {
        return $this->registryWithProviders($uri, new StubDiagnosticProvider([$this->diagnostic('stub')]));
    }

    /** @return array{DiagnosticProviderRegistry, CollectingClient, DiagnosticCollector} */
    private function registryWithProviders(string $uri, DiagnosticProviderInterface ...$providers): array
    {
        return $this->registryWithScope($uri, [], ...$providers);
    }

    /**
     * @param list<string> $excludePaths
     *
     * @return array{DiagnosticProviderRegistry, CollectingClient, DiagnosticCollector}
     */
    private function registryWithScope(string $uri, array $excludePaths, DiagnosticProviderInterface ...$providers): array
    {
        return $this->registryForDocument($uri, 'twig', '', $excludePaths, ...$providers);
    }

    /**
     * @param list<string> $excludePaths
     *
     * @return array{DiagnosticProviderRegistry, CollectingClient, DiagnosticCollector}
     */
    private function registryForDocument(string $uri, string $languageId, string $text, array $excludePaths, DiagnosticProviderInterface ...$providers): array
    {
        $client = new CollectingClient();
        $documents = new DocumentStore();
        $documents->open(new Document($uri, $languageId, 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $fileScope = new ProjectFileScopeRegistry(new GlobPatternCompiler());
        $fileScope->configure($project, $excludePaths);

        $converter = new UriToPathConverter();
        $treeSitter = new NativeTreeSitterParser(new TreeSitterResultDecoder());
        $collector = new DiagnosticCollector(
            $documents,
            $projects,
            new ProjectPathResolver($converter),
            $fileScope,
            $converter,
            new DiagnosticSuppressor(
                new PositionConverter(),
                new LspProtocolMapper(),
                new DiagnosticCodeRegistry(),
                new CommentParserRegistry([
                    'php' => new PhpCommentParser(),
                    'twig' => new TwigCommentParser(),
                    'yaml' => new YamlCommentParser($treeSitter),
                    'xml' => new XmlCommentParser(),
                ]),
            ),
            $providers,
        );

        return [new DiagnosticProviderRegistry(
            $client,
            $documents,
            $projects,
            $collector,
        ), $client, $collector];
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, severity: int, source: string, code: string, message: string} */
    private function diagnostic(string $code, int $line = 0): array
    {
        return [
            'range' => ['start' => ['line' => $line, 'character' => 0], 'end' => ['line' => $line, 'character' => 0]],
            'severity' => 1,
            'source' => 'symfony',
            'code' => $code,
            'message' => 'Stub diagnostic.',
        ];
    }
}

final class StubDiagnosticProvider implements DiagnosticProviderInterface
{
    /** @param list<array<array-key, mixed>>|null $diagnostics */
    public function __construct(
        private readonly ?array $diagnostics,
        private readonly string $name = 'stub',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function diagnostics(array $params): ?array
    {
        return $this->diagnostics;
    }
}

final class ThrowingDiagnosticProvider implements DiagnosticProviderInterface
{
    public function name(): string
    {
        return 'broken-provider';
    }

    public function diagnostics(array $params): ?array
    {
        throw new \RuntimeException('Provider failed.');
    }
}

final class MalformedDiagnosticProvider implements DiagnosticProviderInterface
{
    public function name(): string
    {
        return 'malformed-provider';
    }

    public function diagnostics(array $params): array
    {
        /** @var list<array<array-key, mixed>> $diagnostics */
        $diagnostics = (array) json_decode('[42]', true, flags: \JSON_THROW_ON_ERROR);

        return $diagnostics;
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
