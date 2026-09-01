<?php

namespace Symfony\Lsp\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Server\LanguageServerFactory;
use Symfony\Lsp\Tests\Support\InProcessLanguageServerHarness;
use Symfony\Lsp\Tests\Support\LanguageServerTranscriptAction;
use Symfony\Lsp\Tests\Support\ProtocolMessageExpectation;
use Symfony\Lsp\Tests\Support\RuntimeApplicationFixture;
use Symfony\Lsp\Tests\Support\TestWorkspace;

use function Amp\delay;

final class LanguageServerTest extends TestCase
{
    public function testLifecycleTranscript(): void
    {
        $transcript = (new InProcessLanguageServerHarness())->run([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => new \stdClass()],
            new ProtocolMessageExpectation('the initialize response', static fn (array $message): bool => 1 === ($message['id'] ?? null)),
            ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => new \stdClass()],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'],
            new ProtocolMessageExpectation('the shutdown response', static fn (array $message): bool => 2 === ($message['id'] ?? null)),
            ['jsonrpc' => '2.0', 'method' => 'exit'],
        ]);

        self::assertSame(0, $transcript->exitCode, $transcript->raw);
        self::assertSame([
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'capabilities' => [
                        'positionEncoding' => 'utf-16',
                        'textDocumentSync' => 2,
                        'completionProvider' => [
                            'triggerCharacters' => ["'", '"', '@', '%', ':', '.'],
                        ],
                        'codeActionProvider' => true,
                        'codeLensProvider' => [
                            'resolveProvider' => false,
                        ],
                        'hoverProvider' => true,
                        'definitionProvider' => true,
                        'documentLinkProvider' => [
                            'resolveProvider' => false,
                        ],
                        'referencesProvider' => true,
                        'renameProvider' => [
                            'prepareProvider' => true,
                        ],
                        'executeCommandProvider' => [
                            'commands' => [
                                'symfony.refreshIndex',
                                'symfony.indexStatus',
                                'symfony.switchEnvironment',
                            ],
                        ],
                        'workspace' => [
                            'workspaceFolders' => [
                                'supported' => true,
                                'changeNotifications' => true,
                            ],
                        ],
                    ],
                    'serverInfo' => [
                        'name' => 'Symfony Language Tools',
                        'version' => 'dev',
                    ],
                ],
            ],
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => null,
            ],
        ], $transcript->messages);
    }

    public function testUsesServerVersionForSourceAndRuntimeIndexes(): void
    {
        $fixture = new RuntimeApplicationFixture('9.8.7-test');
        $version = $fixture->serverVersion->value();
        try {
            $transcript = (new InProcessLanguageServerHarness(new LanguageServerFactory($fixture->serverVersion)))->run([
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                    'rootUri' => 'file://'.$fixture->rootPath,
                    'capabilities' => new \stdClass(),
                    'initializationOptions' => ['workspaceTrust' => true],
                ]],
                new ProtocolMessageExpectation('the initialize response', static fn (array $message): bool => 1 === ($message['id'] ?? null)),
                ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []],
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'workspace/executeCommand', 'params' => [
                    'command' => 'symfony.refreshIndex',
                ]],
                new ProtocolMessageExpectation('the refresh response', static fn (array $message): bool => 2 === ($message['id'] ?? null)),
                ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'shutdown', 'params' => []],
                new ProtocolMessageExpectation('the shutdown response', static fn (array $message): bool => 3 === ($message['id'] ?? null)),
                ['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []],
            ]);

            self::assertSame(0, $transcript->exitCode, $transcript->raw);
            self::assertIsArray($transcript->messages[0]['result'] ?? null);
            self::assertIsArray($transcript->messages[0]['result']['serverInfo'] ?? null);
            self::assertSame($version, $transcript->messages[0]['result']['serverInfo']['version'] ?? null);
            self::assertFileExists($fixture->cachePath.'/index/source.jsonl');
            self::assertCount(1, glob($fixture->cachePath.'/*/bridge.php') ?: []);
            self::assertCount(1, glob($fixture->cachePath.'/*/runtime/*.json') ?: []);
        } finally {
            $fixture->cleanup();
        }
    }

    public function testWatchedComposerChangeBootsRuntimeOnce(): void
    {
        $fixture = new RuntimeApplicationFixture();
        $workspace = new TestWorkspace('symfony-lsp-runtime-');
        $logFile = $workspace->write('runtime.log', '');
        $initializationsBeforeChange = 0;
        $initializationsAfterChange = 0;
        $countingPhpCommand = realpath(\dirname(__DIR__).'/Fixtures/counting-php-command.php');
        self::assertIsString($countingPhpCommand);

        try {
            $transcript = (new InProcessLanguageServerHarness(new LanguageServerFactory(
                $fixture->serverVersion,
                [\PHP_BINARY, $countingPhpCommand, $logFile],
            )))->run([
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                    'rootUri' => 'file://'.$fixture->rootPath,
                    'capabilities' => new \stdClass(),
                    'initializationOptions' => ['runtimeIndexing' => true, 'workspaceTrust' => true],
                ]],
                new ProtocolMessageExpectation('the initialize response', static fn (array $message): bool => 1 === ($message['id'] ?? null)),
                ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []],
                new LanguageServerTranscriptAction(function () use ($logFile, &$initializationsBeforeChange): void {
                    $this->waitForBridgeInitializations($logFile, 1);
                    $initializationsBeforeChange = $this->bridgeInitializationCount($logFile);
                }),
                ['jsonrpc' => '2.0', 'method' => 'workspace/didChangeWatchedFiles', 'params' => [
                    'changes' => [['uri' => 'file://'.$fixture->rootPath.'/composer.json', 'type' => 2]],
                ]],
                new LanguageServerTranscriptAction(function () use ($logFile, &$initializationsAfterChange): void {
                    $this->waitForBridgeInitializations($logFile, 2);
                    $initializationsAfterChange = $this->bridgeInitializationCount($logFile);
                }),
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'],
                new ProtocolMessageExpectation('the shutdown response', static fn (array $message): bool => 2 === ($message['id'] ?? null)),
                ['jsonrpc' => '2.0', 'method' => 'exit'],
            ]);

            self::assertSame(0, $transcript->exitCode, $transcript->raw);
            self::assertSame(1, $initializationsBeforeChange);
            self::assertSame(1, $initializationsAfterChange - $initializationsBeforeChange);
        } finally {
            $fixture->cleanup();
            $workspace->cleanup();
        }
    }

    public function testWatchedComposerChangeBootsRuntimeOnceAfterInitialFailure(): void
    {
        $fixture = new RuntimeApplicationFixture();
        $workspace = new TestWorkspace('symfony-lsp-runtime-');
        $logFile = $workspace->write('runtime.log', '');
        $failureMarker = $workspace->path('failed');
        $initializationsBeforeChange = 0;
        $initializationsAfterChange = 0;
        $countingPhpCommand = realpath(\dirname(__DIR__).'/Fixtures/failing-counting-php-command.php');
        self::assertIsString($countingPhpCommand);

        try {
            $transcript = (new InProcessLanguageServerHarness(new LanguageServerFactory(
                $fixture->serverVersion,
                [\PHP_BINARY, $countingPhpCommand, $logFile, $failureMarker],
            )))->run([
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                    'rootUri' => 'file://'.$fixture->rootPath,
                    'capabilities' => new \stdClass(),
                    'initializationOptions' => ['runtimeIndexing' => true, 'workspaceTrust' => true],
                ]],
                new ProtocolMessageExpectation('the initialize response', static fn (array $message): bool => 1 === ($message['id'] ?? null)),
                ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []],
                new LanguageServerTranscriptAction(function () use ($logFile, &$initializationsBeforeChange): void {
                    $this->waitForBridgeInitializations($logFile, 1);
                    $initializationsBeforeChange = $this->bridgeInitializationCount($logFile);
                }),
                ['jsonrpc' => '2.0', 'method' => 'workspace/didChangeWatchedFiles', 'params' => [
                    'changes' => [['uri' => 'file://'.$fixture->rootPath.'/composer.json', 'type' => 2]],
                ]],
                new LanguageServerTranscriptAction(function () use ($logFile, &$initializationsAfterChange): void {
                    $this->waitForBridgeInitializations($logFile, 2);
                    $initializationsAfterChange = $this->bridgeInitializationCount($logFile);
                }),
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'],
                new ProtocolMessageExpectation('the shutdown response', static fn (array $message): bool => 2 === ($message['id'] ?? null)),
                ['jsonrpc' => '2.0', 'method' => 'exit'],
            ]);

            self::assertSame(0, $transcript->exitCode, $transcript->raw);
            self::assertSame(1, $initializationsBeforeChange);
            self::assertSame(1, $initializationsAfterChange - $initializationsBeforeChange);
        } finally {
            $fixture->cleanup();
            $workspace->cleanup();
        }
    }

    public function testWatchedSourceChangesSurviveRediscoveryFailures(): void
    {
        $workspace = new TestWorkspace('symfony-lsp-watched-');
        $workspace->write('composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        $workspace->write('.symfony-lsp.json', json_encode([
            'version' => 1,
            'projectRoots' => ['.'],
        ], \JSON_THROW_ON_ERROR));
        $route = <<<'PHP'
            <?php
            use Symfony\Component\Routing\Attribute\Route;

            final class ArticleController
            {
                #[Route('/article', name: 'article_show')]
                public function show(): void
                {
                }
            }
            PHP;
        $consumer = <<<'PHP'
            <?php
            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            final class ConsumerController extends AbstractController
            {
                public function index(): void
                {
                    $this->generateUrl('article_show');
                }
            }
            PHP;
        $routePath = $workspace->write('src/ArticleController.php', $route);
        $consumerPath = $workspace->write('src/ConsumerController.php', $consumer);
        $consumerUri = 'file://'.$consumerPath;
        $routeOffset = strpos($consumer, 'article_show');
        self::assertIsInt($routeOffset);
        $position = (new PositionConverter())->toPosition($consumer, $routeOffset + 3);
        $before = null;
        $after = null;

        try {
            $transcript = (new InProcessLanguageServerHarness())->run([
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                    'rootUri' => 'file://'.$workspace->rootPath,
                    'capabilities' => new \stdClass(),
                    'initializationOptions' => ['runtimeIndexing' => false],
                ]],
                new ProtocolMessageExpectation('the initialize response', static fn (array $message): bool => 1 === ($message['id'] ?? null)),
                ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []],
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'workspace/executeCommand', 'params' => [
                    'command' => 'symfony.refreshIndex',
                ]],
                new ProtocolMessageExpectation('the refresh response', static fn (array $message): bool => 2 === ($message['id'] ?? null)),
                ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                    'textDocument' => [
                        'uri' => $consumerUri,
                        'languageId' => 'php',
                        'version' => 1,
                        'text' => $consumer,
                    ],
                ]],
                ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'textDocument/definition', 'params' => [
                    'textDocument' => ['uri' => $consumerUri],
                    'position' => ['line' => $position->line, 'character' => $position->character],
                ]],
                new ProtocolMessageExpectation('the initial route definition', static function (array $message) use (&$before): bool {
                    if (3 !== ($message['id'] ?? null)) {
                        return false;
                    }
                    $before = $message['result'] ?? null;

                    return true;
                }),
                new LanguageServerTranscriptAction(static function () use ($workspace, $routePath, $route): void {
                    file_put_contents($routePath, str_replace('article_show', 'article_renamed', $route));
                    $workspace->write('composer.json', '{');
                }),
                ['jsonrpc' => '2.0', 'method' => 'workspace/didChangeWatchedFiles', 'params' => [
                    'changes' => [
                        ['uri' => 'file://'.$workspace->path('composer.json'), 'type' => 2],
                        ['uri' => 'file://'.$routePath, 'type' => 2],
                    ],
                ]],
                new LanguageServerTranscriptAction(static function (): void {
                    delay(0.5);
                }),
                ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'textDocument/definition', 'params' => [
                    'textDocument' => ['uri' => $consumerUri],
                    'position' => ['line' => $position->line, 'character' => $position->character],
                ]],
                new ProtocolMessageExpectation('the updated route definition', static function (array $message) use (&$after): bool {
                    if (4 !== ($message['id'] ?? null)) {
                        return false;
                    }
                    $after = $message['result'] ?? null;

                    return true;
                }),
                ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'shutdown'],
                new ProtocolMessageExpectation('the shutdown response', static fn (array $message): bool => 5 === ($message['id'] ?? null)),
                ['jsonrpc' => '2.0', 'method' => 'exit'],
            ]);

            self::assertSame(0, $transcript->exitCode, $transcript->raw);
            self::assertIsArray($before);
            self::assertCount(1, $before);
            self::assertSame([], $after);
        } finally {
            $workspace->cleanup();
        }
    }

    #[DataProvider('composerFileProvider')]
    public function testWatchedComposerChangesCanCreateProgressWithoutDeadlockingListener(string $composerFile): void
    {
        $fixture = new RuntimeApplicationFixture();
        try {
            $transcript = (new InProcessLanguageServerHarness(new LanguageServerFactory($fixture->serverVersion)))->run([
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                    'rootUri' => 'file://'.$fixture->rootPath,
                    'capabilities' => ['window' => ['workDoneProgress' => true]],
                    'initializationOptions' => ['runtimeIndexing' => false],
                ]],
                new ProtocolMessageExpectation('the initialize response', static fn (array $message): bool => 1 === ($message['id'] ?? null) && !isset($message['method'])),
                ['jsonrpc' => '2.0', 'method' => 'workspace/didChangeWatchedFiles', 'params' => [
                    'changes' => [['uri' => 'file://'.$fixture->rootPath.'/'.$composerFile, 'type' => 2]],
                ]],
                new ProtocolMessageExpectation('the progress creation request', static fn (array $message): bool => 'window/workDoneProgress/create' === ($message['method'] ?? null)),
                ['jsonrpc' => '2.0', 'id' => 1, 'result' => null],
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'],
                new ProtocolMessageExpectation('the shutdown response', static fn (array $message): bool => 2 === ($message['id'] ?? null) && !isset($message['method'])),
                ['jsonrpc' => '2.0', 'method' => 'exit'],
            ]);

            self::assertSame(0, $transcript->exitCode, $transcript->raw);
            self::assertContains('window/workDoneProgress/create', array_column($transcript->messages, 'method'));
        } finally {
            $fixture->cleanup();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function composerFileProvider(): iterable
    {
        yield 'manifest' => ['composer.json'];
        yield 'lock file' => ['composer.lock'];
    }

    public function testReportsCancelledFeatureRequestsWithoutAnInternalError(): void
    {
        $transcript = (new InProcessLanguageServerHarness())->run([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []],
            new ProtocolMessageExpectation('the initialize response', static fn (array $message): bool => 1 === ($message['id'] ?? null)),
            ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'textDocument/completion', 'params' => []],
            ['jsonrpc' => '2.0', 'method' => '$/cancelRequest', 'params' => ['id' => 2]],
            new ProtocolMessageExpectation('the cancellation response', static fn (array $message): bool => 2 === ($message['id'] ?? null)),
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'shutdown', 'params' => []],
            new ProtocolMessageExpectation('the shutdown response', static fn (array $message): bool => 3 === ($message['id'] ?? null)),
            ['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []],
        ]);

        self::assertSame(0, $transcript->exitCode, $transcript->raw);
        self::assertSame([
            'jsonrpc' => '2.0',
            'id' => 2,
            'error' => ['code' => -32800, 'message' => 'Request cancelled.'],
        ], $transcript->messages[1]);
    }

    public function testRejectsFeatureRequestsBeforeInitialization(): void
    {
        $transcript = (new InProcessLanguageServerHarness())->run([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'textDocument/completion', 'params' => []],
            new ProtocolMessageExpectation('the rejection response', static fn (array $message): bool => 1 === ($message['id'] ?? null)),
            ['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []],
        ]);

        self::assertSame(1, $transcript->exitCode, $transcript->raw);
        self::assertSame([[
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32002,
                'message' => 'The server has not been initialized.',
            ],
        ]], $transcript->messages);
    }

    public function testExitWithoutShutdownIsUnsuccessful(): void
    {
        $transcript = (new InProcessLanguageServerHarness())->run([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []],
            new ProtocolMessageExpectation('the initialize response', static fn (array $message): bool => 1 === ($message['id'] ?? null)),
            ['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []],
        ]);

        self::assertSame(1, $transcript->exitCode, $transcript->raw);
    }

    private function waitForBridgeInitializations(string $logFile, int $minimum): void
    {
        $deadline = microtime(true) + 15;
        $lastContents = null;
        $quiescentSince = null;
        while (microtime(true) < $deadline) {
            $contents = file_get_contents($logFile);
            if (false === $contents) {
                throw new \RuntimeException('The runtime bridge log is unavailable.');
            }
            $initializations = substr_count($contents, "start\n");
            $completed = substr_count($contents, "finish\n");
            if ($initializations >= $minimum && $initializations === $completed) {
                if ($contents === $lastContents) {
                    $quiescentSince ??= microtime(true);
                    if (microtime(true) - $quiescentSince >= 0.5) {
                        return;
                    }
                } else {
                    $quiescentSince = null;
                }
            } else {
                $quiescentSince = null;
            }
            $lastContents = $contents;
            delay(0.01);
        }

        self::fail('The runtime bridge did not become quiescent.');
    }

    private function bridgeInitializationCount(string $logFile): int
    {
        $contents = file_get_contents($logFile);
        if (false === $contents) {
            throw new \RuntimeException('The runtime bridge log is unavailable.');
        }

        return substr_count($contents, "start\n");
    }
}
