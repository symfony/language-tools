<?php

namespace Symfony\Lsp\Tests\Server;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Fabpot\JsonRpc\ContentLengthJsonRpcTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Server\LanguageServerFactory;
use Symfony\Lsp\Server\ServerVersion;
use Symfony\Lsp\Tests\Support\CapturingWritableStream;

use function Amp\delay;

final class LanguageServerTest extends TestCase
{
    public function testLifecycleTranscript(): void
    {
        $input = new ReadableBuffer(
            $this->frame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => new \stdClass()]).
            $this->frame(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => new \stdClass()]).
            $this->frame(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown']).
            $this->frame(['jsonrpc' => '2.0', 'method' => 'exit'])
        );
        $output = new CapturingWritableStream();

        $exitCode = (new LanguageServerFactory())->create($input, $output)->run();

        self::assertSame(0, $exitCode);
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
        ], $this->decodeFrames($output->contents()));
    }

    public function testUsesServerVersionForSourceAndRuntimeIndexes(): void
    {
        $version = '9.8.7-test';
        $root = realpath(\dirname(__DIR__).'/Fixtures/RuntimeApplication');
        self::assertIsString($root);
        $input = new ReadableBuffer(
            $this->frame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'rootUri' => 'file://'.$root,
                'capabilities' => new \stdClass(),
                'initializationOptions' => ['workspaceTrust' => true],
            ]]).
            $this->frame(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []]).
            $this->frame(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'workspace/executeCommand', 'params' => [
                'command' => 'symfony.refreshIndex',
            ]]).
            $this->frame(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'shutdown', 'params' => []]).
            $this->frame(['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []])
        );
        $output = new CapturingWritableStream();

        try {
            $exitCode = (new LanguageServerFactory(new ServerVersion($version)))->create($input, $output)->run();
            $messages = $this->decodeFrames($output->contents());

            self::assertSame(0, $exitCode);
            self::assertIsArray($messages[0]['result'] ?? null);
            self::assertIsArray($messages[0]['result']['serverInfo'] ?? null);
            self::assertSame($version, $messages[0]['result']['serverInfo']['version'] ?? null);
            self::assertFileExists($root.'/var/symfony-lsp/'.$version.'/index/source.jsonl');
            self::assertCount(1, glob($root.'/var/symfony-lsp/'.$version.'/*/bridge.php') ?: []);
            self::assertCount(1, glob($root.'/var/symfony-lsp/'.$version.'/*/runtime/*.json') ?: []);
        } finally {
            $this->removeDirectory($root.'/var/symfony-lsp/'.$version);
        }
    }

    public function testWatchedComposerChangeBootsRuntimeOnce(): void
    {
        $root = realpath(\dirname(__DIR__).'/Fixtures/RuntimeApplication');
        self::assertIsString($root);
        $logFile = tempnam(sys_get_temp_dir(), 'symfony-lsp-runtime-');
        self::assertIsString($logFile);
        $initializationsBeforeChange = 0;
        $initializationsAfterChange = 0;
        $composerFile = $root.'/composer.json';
        $composerContents = file_get_contents($composerFile);
        self::assertIsString($composerContents);
        $frames = [
            $this->frame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'rootUri' => 'file://'.$root,
                'capabilities' => new \stdClass(),
                'initializationOptions' => ['runtimeIndexing' => true, 'workspaceTrust' => true],
            ]]),
            $this->frame(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []]),
            $this->frame(['jsonrpc' => '2.0', 'method' => 'workspace/didChangeWatchedFiles', 'params' => [
                'changes' => [['uri' => 'file://'.$root.'/composer.json', 'type' => 2]],
            ]]),
            $this->frame(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown']),
            $this->frame(['jsonrpc' => '2.0', 'method' => 'exit']),
        ];
        $input = new ReadableIterableStream((function () use ($frames, $logFile, $composerFile, $composerContents, &$initializationsBeforeChange, &$initializationsAfterChange): \Generator {
            yield $frames[0];
            yield $frames[1];
            $this->waitForBridgeInitializations($logFile, 1);
            $initializationsBeforeChange = $this->bridgeInitializationCount($logFile);
            file_put_contents($composerFile, $composerContents."\n");
            yield $frames[2];
            $this->waitForBridgeInitializations($logFile, 2);
            $initializationsAfterChange = $this->bridgeInitializationCount($logFile);
            yield $frames[3];
            yield $frames[4];
        })());
        $output = new CapturingWritableStream();
        $countingPhpCommand = realpath(\dirname(__DIR__).'/Fixtures/counting-php-command.php');
        self::assertIsString($countingPhpCommand);

        try {
            self::assertSame(0, (new LanguageServerFactory(defaultPhpCommand: [\PHP_BINARY, $countingPhpCommand, $logFile]))->create($input, $output)->run());
            self::assertSame(1, $initializationsBeforeChange);
            self::assertSame(1, $initializationsAfterChange - $initializationsBeforeChange);
        } finally {
            file_put_contents($composerFile, $composerContents);
            $this->removeDirectory($root.'/var/symfony-lsp/dev');
            @unlink($logFile);
        }
    }

    #[DataProvider('composerFileProvider')]
    public function testWatchedComposerChangesCanCreateProgressWithoutDeadlockingListener(string $composerFile): void
    {
        $root = realpath(\dirname(__DIR__).'/Fixtures/RuntimeApplication');
        self::assertIsString($root);
        $frames = [
            $this->frame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'rootUri' => 'file://'.$root,
                'capabilities' => ['window' => ['workDoneProgress' => true]],
                'initializationOptions' => ['runtimeIndexing' => false],
            ]]),
            $this->frame(['jsonrpc' => '2.0', 'method' => 'workspace/didChangeWatchedFiles', 'params' => [
                'changes' => [['uri' => 'file://'.$root.'/'.$composerFile, 'type' => 2]],
            ]]),
            $this->frame(['jsonrpc' => '2.0', 'id' => 1, 'result' => null]),
            $this->frame(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown']),
            $this->frame(['jsonrpc' => '2.0', 'method' => 'exit']),
        ];
        $input = new ReadableIterableStream((static function () use ($frames): \Generator {
            foreach ($frames as $frame) {
                yield $frame;
                delay(0);
            }
        })());
        $output = new CapturingWritableStream();

        try {
            self::assertSame(0, (new LanguageServerFactory())->create($input, $output)->run());
            self::assertContains('window/workDoneProgress/create', array_column($this->decodeFrames($output->contents()), 'method'));
        } finally {
            $this->removeDirectory($root.'/var/symfony-lsp/dev');
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
        $input = new ReadableBuffer(
            $this->frame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]).
            $this->frame(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []]).
            $this->frame(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'textDocument/completion', 'params' => []]).
            $this->frame(['jsonrpc' => '2.0', 'method' => '$/cancelRequest', 'params' => ['id' => 2]]).
            $this->frame(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'shutdown', 'params' => []]).
            $this->frame(['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []])
        );
        $output = new CapturingWritableStream();

        self::assertSame(0, (new LanguageServerFactory())->create($input, $output)->run());
        $responses = $this->decodeFrames($output->contents());
        self::assertSame([
            'jsonrpc' => '2.0',
            'id' => 2,
            'error' => ['code' => -32800, 'message' => 'Request cancelled.'],
        ], $responses[1]);
    }

    public function testRejectsFeatureRequestsBeforeInitialization(): void
    {
        $input = new ReadableBuffer(
            $this->frame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'textDocument/completion', 'params' => []]).
            $this->frame(['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []])
        );
        $output = new CapturingWritableStream();

        self::assertSame(1, (new LanguageServerFactory())->create($input, $output)->run());
        self::assertSame([[
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32002,
                'message' => 'The server has not been initialized.',
            ],
        ]], $this->decodeFrames($output->contents()));
    }

    public function testExitWithoutShutdownIsUnsuccessful(): void
    {
        $input = new ReadableBuffer(
            $this->frame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]).
            $this->frame(['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []])
        );

        self::assertSame(1, (new LanguageServerFactory())
            ->create($input, new CapturingWritableStream())
            ->run());
    }

    /**
     * @param array<array-key, mixed> $message
     */
    private function frame(array $message): string
    {
        $json = json_encode($message, \JSON_THROW_ON_ERROR);

        return 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function decodeFrames(string $frames): array
    {
        $transport = new ContentLengthJsonRpcTransport(
            new ReadableBuffer($frames),
            new CapturingWritableStream(),
        );
        $messages = [];

        while (null !== $message = $transport->receive()) {
            $decoded = json_decode($message, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $messages[] = $decoded;
        }

        return $messages;
    }

    private function waitForBridgeInitializations(string $logFile, int $minimum): void
    {
        $deadline = microtime(true) + 15;
        $lastContents = null;
        $quiescentSince = null;
        while (microtime(true) < $deadline) {
            $contents = file_get_contents($logFile);
            self::assertIsString($contents);
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
        self::assertIsString($contents);

        return substr_count($contents, "start\n");
    }

    private function removeDirectory(string $directory): void
    {
        (new Filesystem())->remove($directory);
    }
}
