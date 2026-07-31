<?php

namespace Symfony\Lsp\Tests\Server;

use Amp\ByteStream\ReadableBuffer;
use Fabpot\JsonRpc\ContentLengthJsonRpcTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Server\LanguageServerFactory;
use Symfony\Lsp\Tests\Support\CapturingWritableStream;

final class LanguageServerTest extends TestCase
{
    public function testLifecycleTranscript(): void
    {
        $input = new ReadableBuffer(
            $this->frame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]).
            $this->frame(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []]).
            $this->frame(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown', 'params' => []]).
            $this->frame(['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []])
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
                            'triggerCharacters' => ["'", '"', '@', '%'],
                        ],
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
                        'name' => 'Symfony LSP',
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
}
