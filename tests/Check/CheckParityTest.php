<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Check\CheckCommand;
use Symfony\Lsp\Server\LanguageServerFactory;
use Symfony\Lsp\Tests\Support\InProcessLanguageServerHarness;
use Symfony\Lsp\Tests\Support\ProtocolMessageExpectation;
use Symfony\Lsp\Tests\Support\RuntimeApplicationFixture;
use Symfony\Lsp\Tests\Support\TestWorkspace;

/**
 * @phpstan-type ProtocolDiagnostic array{
 *     range: array{start: array{line: int, character: int}, end: array{line: int, character: int}},
 *     severity: int,
 *     code: string,
 *     source: string,
 *     message: string
 * }
 * @phpstan-type HeadlessDiagnostic array{
 *     range: array{start: array{line: int, character: int}, end: array{line: int, character: int}},
 *     severity: string,
 *     code: string,
 *     source: string,
 *     message: string
 * }
 */
final class CheckParityTest extends TestCase
{
    private TestWorkspace $workspace;
    private string $uri;
    private string $text;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-parity-');
        $this->workspace->write('composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        $this->text = "parameters:\n    broken: '😀%env(APP_SECRET%'\n";
        $this->workspace->write('config/services.yaml', $this->text);
        $this->uri = 'file://'.$this->workspace->path('config/services.yaml');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testSourceOnlySavedDiagnosticsMatchTheFinalLspPublicationAfterRefresh(): void
    {
        $factory = new LanguageServerFactory();
        $lspDiagnostics = $this->publishedDiagnostics(
            $factory,
            $this->workspace->rootPath,
            $this->uri,
            'yaml',
            $this->text,
            ['runtimeIndexing' => false],
        );
        $headlessDiagnostics = $this->headlessDiagnostics($factory, $this->workspace->rootPath, 'config/services.yaml', true);

        $this->assertSameDiagnostics($lspDiagnostics, $headlessDiagnostics);
        self::assertSame(15, $headlessDiagnostics[0]['range']['start']['character']);
    }

    public function testSourceSuppressionsMatchBetweenTheEditorAndChecker(): void
    {
        $text = "parameters:\n    # @symfony-lsp-ignore env.malformed_chain (intentional malformed expression)\n    broken: '😀%env(APP_SECRET%'\n";
        $this->workspace->write('config/services.yaml', $text);
        $factory = new LanguageServerFactory();

        $lspDiagnostics = $this->publishedDiagnostics(
            $factory,
            $this->workspace->rootPath,
            $this->uri,
            'yaml',
            $text,
            ['runtimeIndexing' => false],
        );
        $headlessDiagnostics = $this->headlessDiagnostics(
            $factory,
            $this->workspace->rootPath,
            'config/services.yaml',
            true,
            CheckCommand::EXIT_SUCCESS,
        );

        self::assertSame([], $lspDiagnostics);
        self::assertSame([], $headlessDiagnostics);
    }

    public function testRuntimeReadySavedDiagnosticsMatchTheFinalLspPublicationAfterRefresh(): void
    {
        $fixture = new RuntimeApplicationFixture();
        $path = $fixture->rootPath.'/templates/components/Search.html.twig';
        $text = file_get_contents($path);
        self::assertIsString($text);
        $factory = new LanguageServerFactory($fixture->serverVersion);
        try {
            $lspDiagnostics = $this->publishedDiagnostics(
                $factory,
                $fixture->rootPath,
                'file://'.$path,
                'twig',
                $text,
                ['workspaceTrust' => true],
            );
            $headlessDiagnostics = $this->headlessDiagnostics($factory, $fixture->rootPath, 'templates/components/Search.html.twig', false);

            $this->assertSameDiagnostics($lspDiagnostics, $headlessDiagnostics);
            self::assertSame('stimulus.unknown_controller', $headlessDiagnostics[0]['code']);
        } finally {
            $fixture->cleanup();
        }
    }

    /**
     * @param array<array-key, mixed> $initializationOptions
     *
     * @return list<ProtocolDiagnostic>
     */
    private function publishedDiagnostics(LanguageServerFactory $factory, string $root, string $uri, string $languageId, string $text, array $initializationOptions): array
    {
        $transcript = (new InProcessLanguageServerHarness($factory))->run([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'rootUri' => 'file://'.$root,
                'capabilities' => ['general' => ['positionEncodings' => ['utf-16']]],
                'initializationOptions' => $initializationOptions,
            ]],
            new ProtocolMessageExpectation('the initialize response', static fn (array $message): bool => 1 === ($message['id'] ?? null)),
            ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'workspace/executeCommand', 'params' => [
                'command' => 'symfony.refreshIndex',
            ]],
            new ProtocolMessageExpectation('the refresh response', static fn (array $message): bool => 2 === ($message['id'] ?? null)),
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => [
                    'uri' => $uri,
                    'languageId' => $languageId,
                    'version' => 1,
                    'text' => $text,
                ],
            ]],
            new ProtocolMessageExpectation('the diagnostic publication', static function (array $message) use ($uri): bool {
                $params = $message['params'] ?? null;

                return 'textDocument/publishDiagnostics' === ($message['method'] ?? null)
                    && \is_array($params)
                    && $uri === ($params['uri'] ?? null);
            }),
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'shutdown', 'params' => []],
            new ProtocolMessageExpectation('the shutdown response', static fn (array $message): bool => 3 === ($message['id'] ?? null)),
            ['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []],
        ]);
        self::assertSame(0, $transcript->exitCode, $transcript->raw);
        $publications = [];
        foreach ($transcript->messages as $message) {
            $params = $message['params'] ?? null;
            if ('textDocument/publishDiagnostics' === ($message['method'] ?? null)
                && \is_array($params)
                && $uri === ($params['uri'] ?? null)
            ) {
                $publications[] = $message;
            }
        }
        self::assertCount(1, $publications);
        $params = $publications[0]['params'] ?? null;
        self::assertIsArray($params);
        /** @var list<ProtocolDiagnostic>|null $diagnostics */
        $diagnostics = $params['diagnostics'] ?? null;
        self::assertIsArray($diagnostics);

        return $diagnostics;
    }

    /** @return list<HeadlessDiagnostic> */
    private function headlessDiagnostics(LanguageServerFactory $factory, string $root, string $path, bool $sourceOnly, int $expectedExitCode = CheckCommand::EXIT_DIAGNOSTICS): array
    {
        $arguments = [
            '--format=json',
            '--workspace='.$root,
            $path,
        ];
        if ($sourceOnly) {
            array_unshift($arguments, '--source-only');
        }
        $execution = $factory->createCheck()->run($arguments);
        self::assertSame($expectedExitCode, $execution->exitCode, $execution->stderr);
        $report = json_decode($execution->stdout, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        $items = $report['diagnostics'] ?? null;
        self::assertIsArray($items);
        /** @var list<HeadlessDiagnostic> $diagnostics */
        $diagnostics = $items;

        return $diagnostics;
    }

    /**
     * @param list<ProtocolDiagnostic> $lspDiagnostics
     * @param list<HeadlessDiagnostic> $headlessDiagnostics
     */
    private function assertSameDiagnostics(array $lspDiagnostics, array $headlessDiagnostics): void
    {
        self::assertSame(array_map(static fn (array $diagnostic): array => [
            'range' => $diagnostic['range'],
            'severity' => $diagnostic['severity'],
            'code' => $diagnostic['code'],
            'source' => $diagnostic['source'],
            'message' => $diagnostic['message'],
        ], $lspDiagnostics), array_map(static fn (array $diagnostic): array => [
            'range' => $diagnostic['range'],
            'severity' => match ($diagnostic['severity']) {
                'error' => 1,
                'warning' => 2,
                'information' => 3,
                'hint' => 4,
                default => throw new \UnexpectedValueException('Unknown diagnostic severity.'),
            },
            'code' => $diagnostic['code'],
            'source' => $diagnostic['source'],
            'message' => $diagnostic['message'],
        ], $headlessDiagnostics));
    }
}
