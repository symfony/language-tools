<?php

namespace Symfony\Lsp\Tests\Feature;

use Amp\ByteStream\WritableBuffer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DetailedDiagnosticCollection;
use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Feature\DiagnosticCollector;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderRegistry;
use Symfony\Lsp\Feature\DiagnosticSuppressor;
use Symfony\Lsp\Feature\EnvironmentScopedDiagnosticFilter;
use Symfony\Lsp\Feature\PartialParseDiagnosticFilter;
use Symfony\Lsp\Index\SourceOverlayHealthRegistry;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Xml\TolerantXmlParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\LspRequestFactory;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;
use Symfony\Lsp\Tests\Support\EnvironmentScopes;
use Symfony\Lsp\Tests\Support\ProjectPaths;
use Symfony\Lsp\Tests\Support\RecordingClient;

final class DiagnosticProviderRegistryTest extends TestCase
{
    private ?WritableBuffer $log = null;

    public function testCollectsAndPublishesProviderDiagnosticsForProjectDocuments(): void
    {
        [$registry, $client, $collector] = $this->registry($uri = 'file:///workspace/templates/page.html.twig');

        $collected = $this->diagnostics($collector, $uri);
        $registry->publish($uri);

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

        $registry->publish('file:///workspace/templates/page.html.twig');

        self::assertSame([], $client->notifications);
    }

    public function testCollectionKeepsProviderTimingsWhenNoProviderAnalyzesTheDocument(): void
    {
        [, , $collector] = $this->registryWithProviders(
            'file:///workspace/templates/page.html.twig',
            new StubDiagnosticProvider(null, 'first-provider'),
            new StubDiagnosticProvider(null, 'second-provider'),
        );

        $collection = $collector->collect('file:///workspace/templates/page.html.twig', measureProviders: true);

        self::assertFalse($collection->analyzed);
        self::assertSame([], $collection->diagnostics);
        self::assertSame([], $collection->failures);
        self::assertSame(['first-provider', 'second-provider'], array_keys($collection->providerNanoseconds));
    }

    public function testPublishesAnEmptyListWhenAProviderMatchesWithoutDiagnostics(): void
    {
        [$registry, $client] = $this->registryWithProviders(
            'file:///workspace/templates/page.html.twig',
            new StubDiagnosticProvider([]),
        );

        $registry->publish('file:///workspace/templates/page.html.twig');

        self::assertCount(1, $client->notifications);
        self::assertSame([], $client->notifications[0]['params']['diagnostics']);
    }

    public function testPublishesSuccessfulProvidersAroundFailuresAndLogsTheFailures(): void
    {
        $this->log = $log = new WritableBuffer();
        [$registry, $client] = $this->registryWithProviders(
            'file:///workspace/templates/page.html.twig',
            new StubDiagnosticProvider([$this->diagnostic('first')], 'first-provider'),
            new ThrowingDiagnosticProvider(),
            new StubDiagnosticProvider([$this->diagnostic('third')], 'third-provider'),
        );

        $registry->publish('file:///workspace/templates/page.html.twig');
        $log->close();

        $diagnostics = $client->notifications[0]['params']['diagnostics'] ?? null;
        self::assertIsArray($diagnostics);
        self::assertSame(['first', 'third'], array_column($diagnostics, 'code'));
        self::assertStringContainsString('The "broken-provider" diagnostic provider failed: Provider failed.', $log->buffer());
    }

    public function testCollectionKeepsSuccessfulProvidersAroundFailures(): void
    {
        [, , $collector] = $this->registryWithProviders(
            'file:///workspace/templates/page.html.twig',
            new StubDiagnosticProvider([$this->diagnostic('first')], 'first-provider'),
            new ThrowingDiagnosticProvider(),
            new MalformedDiagnosticProvider(),
            new StubDiagnosticProvider([$this->diagnostic('third')], 'third-provider'),
        );

        $collection = $collector->collect('file:///workspace/templates/page.html.twig', measureProviders: true);

        self::assertSame(['first-provider', 'broken-provider', 'malformed-provider', 'third-provider'], array_keys($collection->providerNanoseconds));
        foreach ($collection->providerNanoseconds as $nanoseconds) {
            self::assertGreaterThanOrEqual(0, $nanoseconds);
        }
        self::assertSame(['first-provider', 'third-provider'], array_map(static fn ($diagnostic): string => $diagnostic->provider, $collection->diagnostics));
        self::assertSame(['first', 'third'], array_column(array_map(static fn ($diagnostic): array => $diagnostic->diagnostic, $collection->diagnostics), 'code'));
        self::assertSame(['broken-provider', 'malformed-provider'], array_map(static fn ($failure): string => $failure->provider, $collection->failures));
        self::assertSame('Provider failed.', $collection->failures[0]->error->getMessage());
        self::assertSame('A diagnostic provider returned a non-array diagnostic.', $collection->failures[1]->error->getMessage());
    }

    public function testDropsSelectedEnvironmentDiagnosticsFromDocumentsAnotherEnvironmentLoads(): void
    {
        $uri = 'file:///workspace/config/packages/test/security.yaml';
        [$registry, $client, $collector] = $this->registryForDocument(
            $uri,
            'yaml',
            '',
            [],
            new StubDiagnosticProvider([$this->diagnostic('security.unknown_provider')], 'security'),
            new StubDiagnosticProvider([$this->diagnostic('env.unknown_processor'), $this->diagnostic('env.malformed_chain')], 'environment'),
        );
        $registry->publish($uri);

        $published = $client->notifications[0]['params']['diagnostics'];

        self::assertSame(['env.malformed_chain'], array_column($this->diagnostics($collector, $uri), 'code'));
        self::assertIsArray($published);
        self::assertSame(['env.malformed_chain'], array_column($published, 'code'));
    }

    public function testKeepsSelectedEnvironmentDiagnosticsInDocumentsThatEnvironmentLoads(): void
    {
        $codes = ['security.unknown_provider', 'env.unknown_processor'];

        foreach (['config/packages/dev/security.yaml', 'config/packages/security.yaml', 'config/services_dev.yaml'] as $path) {
            $uri = 'file:///workspace/'.$path;
            [, , $collector] = $this->registryForDocument(
                $uri,
                'yaml',
                '',
                [],
                new StubDiagnosticProvider(array_map($this->diagnostic(...), $codes)),
            );

            self::assertSame($codes, array_column($this->diagnostics($collector, $uri), 'code'), $path);
        }
    }

    public function testSuppressesDiagnosticsInPublishedAndCollectedDiagnostics(): void
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
        $registry->publish($uri);
        $detailed = $collector->collect($uri);

        self::assertSame([], $client->notifications[0]['params']['diagnostics']);
        self::assertInstanceOf(DetailedDiagnosticCollection::class, $detailed);
        self::assertSame([], $detailed->diagnostics);
    }

    public function testMergesProviderDiagnosticsInOrder(): void
    {
        [$registry, $client] = $this->registryWithProviders(
            'file:///workspace/templates/page.html.twig',
            new StubDiagnosticProvider(null),
            new StubDiagnosticProvider([$this->diagnostic('second')]),
            new StubDiagnosticProvider([$this->diagnostic('third')]),
        );

        $registry->publish('file:///workspace/templates/page.html.twig');

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
        $registry->publish($uri = 'file:///workspace/tests/Fixtures/page.html.twig');

        self::assertSame([], $client->notifications[0]['params']['diagnostics']);
        self::assertSame(['stub'], array_column($this->diagnostics($collector, $uri, true), 'code'));
    }

    public function testSuppressesGitignoredDocumentsEvenWhenExcludedPathsAreIncluded(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($root.'/templates', 0777, true);
        file_put_contents($root.'/.gitignore', "templates/\n");
        file_put_contents($root.'/templates/page.html.twig', '');
        $uri = (new UriToPathConverter())->toUri($root.'/templates/page.html.twig');

        try {
            [, , $collector] = $this->registryForProjectDocument(
                $root,
                $uri,
                'twig',
                '',
                [],
                new StubDiagnosticProvider([$this->diagnostic('stub')]),
            );
            self::assertSame([], $this->diagnostics($collector, $uri));
            self::assertSame([], $this->diagnostics($collector, $uri, true));
            $detailed = $collector->collect($uri);
            self::assertInstanceOf(DetailedDiagnosticCollection::class, $detailed);
            self::assertSame([], $detailed->diagnostics);
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    public function testSuppressesDiagnosticsInDependencyOwnedDocuments(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        file_put_contents($root.'/.gitignore', "/var/\n");
        $converter = new UriToPathConverter();

        try {
            foreach ([
                'vendor/acme/bundle/templates/alert.html.twig',
                'node_modules/lib/index.js',
                'var/cache/dev/template.php',
            ] as $relativePath) {
                mkdir($root.'/'.\dirname($relativePath), 0777, true);
                file_put_contents($root.'/'.$relativePath, '');
                $uri = $converter->toUri($root.'/'.$relativePath);
                [$registry, $client] = $this->registryForProjectDocument($root, $uri, 'twig', '', [], new StubDiagnosticProvider([$this->diagnostic('stub')]));

                $registry->publish($uri);

                self::assertCount(1, $client->notifications, $uri);
                self::assertSame([], $client->notifications[0]['params']['diagnostics'], $uri);
            }
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    /** @return array{DiagnosticProviderRegistry, RecordingClient, DiagnosticCollector} */
    private function registry(string $uri): array
    {
        return $this->registryWithProviders($uri, new StubDiagnosticProvider([$this->diagnostic('stub')]));
    }

    /** @return array{DiagnosticProviderRegistry, RecordingClient, DiagnosticCollector} */
    private function registryWithProviders(string $uri, DiagnosticProviderInterface ...$providers): array
    {
        return $this->registryWithScope($uri, [], ...$providers);
    }

    /**
     * @param list<string> $excludePaths
     *
     * @return array{DiagnosticProviderRegistry, RecordingClient, DiagnosticCollector}
     */
    private function registryWithScope(string $uri, array $excludePaths, DiagnosticProviderInterface ...$providers): array
    {
        return $this->registryForDocument($uri, 'twig', '', $excludePaths, ...$providers);
    }

    /**
     * @param list<string> $excludePaths
     *
     * @return array{DiagnosticProviderRegistry, RecordingClient, DiagnosticCollector}
     */
    private function registryForDocument(string $uri, string $languageId, string $text, array $excludePaths, DiagnosticProviderInterface ...$providers): array
    {
        return $this->registryForProjectDocument('/workspace', $uri, $languageId, $text, $excludePaths, ...$providers);
    }

    /**
     * @param list<string> $excludePaths
     *
     * @return array{DiagnosticProviderRegistry, RecordingClient, DiagnosticCollector}
     */
    private function registryForProjectDocument(string $rootPath, string $uri, string $languageId, string $text, array $excludePaths, DiagnosticProviderInterface ...$providers): array
    {
        $client = new RecordingClient();
        $documents = new DocumentStore();
        $documents->open(new Document($uri, $languageId, 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project($rootPath, (new UriToPathConverter())->toUri($rootPath))]);
        $fileScope = new ProjectFileScopeRegistry(new GlobPatternCompiler());
        $fileScope->configure($project, $excludePaths);

        $converter = new UriToPathConverter();
        $treeSitter = new NativeTreeSitterParser(new TreeSitterResultDecoder());
        $collector = new DiagnosticCollector(
            $documents,
            $projects,
            new LspRequestFactory($documents, $projects, new PositionConverter()),
            $fileScope,
            $converter,
            ProjectPaths::policy(),
            new PartialParseDiagnosticFilter(new SourceOverlayHealthRegistry()),
            new EnvironmentScopedDiagnosticFilter($projects, EnvironmentScopes::resolver(), new DiagnosticCodeRegistry()),
            new DiagnosticSuppressor(
                new PositionConverter(),
                new LspProtocolMapper(),
                new DiagnosticCodeRegistry(),
                new CommentParserRegistry([
                    'php' => new PhpCommentParser(),
                    'twig' => new TwigCommentParser(),
                    'yaml' => new YamlCommentParser($treeSitter),
                    'xml' => new XmlCommentParser(new TolerantXmlParser()),
                ]),
            ),
            $providers,
        );

        return [new DiagnosticProviderRegistry(
            $client,
            $documents,
            $projects,
            $collector,
            new ServerLogger($this->log, new SensitiveDataRedactor()),
        ), $client, $collector];
    }

    /** @return list<array<array-key, mixed>> */
    private function diagnostics(DiagnosticCollector $collector, string $uri, bool $includeExcluded = false): array
    {
        return array_map(static fn ($diagnostic): array => $diagnostic->diagnostic, $collector->collect($uri, $includeExcluded)->diagnostics);
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

    public function diagnostics(DocumentRequest $request): ?array
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

    public function diagnostics(DocumentRequest $request): ?array
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

    public function diagnostics(DocumentRequest $request): array
    {
        /** @var list<array<array-key, mixed>> $diagnostics */
        $diagnostics = (array) json_decode('[42]', true, flags: \JSON_THROW_ON_ERROR);

        return $diagnostics;
    }
}
