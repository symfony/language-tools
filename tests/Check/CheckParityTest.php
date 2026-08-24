<?php

namespace Symfony\Lsp\Tests\Check;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Fabpot\JsonRpc\ContentLengthJsonRpcTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Server\LanguageServerFactory;
use Symfony\Lsp\Tests\Support\CapturingWritableStream;

use function Amp\delay;

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
    private string $directory;
    private string $uri;
    private string $text;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/symfony-lsp-parity-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/config', 0777, true);
        file_put_contents($this->directory.'/composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        $this->text = "parameters:\n    broken: '😀%env(APP_SECRET%'\n";
        file_put_contents($this->directory.'/config/services.yaml', $this->text);
        $this->uri = 'file://'.$this->directory.'/config/services.yaml';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testSourceOnlySavedDiagnosticsMatchTheFinalLspPublicationAfterRefresh(): void
    {
        $lspDiagnostics = $this->publishedDiagnostics(
            $this->directory,
            $this->uri,
            'yaml',
            $this->text,
            ['runtimeIndexing' => false],
        );
        $headlessDiagnostics = $this->headlessDiagnostics($this->directory, 'config/services.yaml', true);

        $this->assertSameDiagnostics($lspDiagnostics, $headlessDiagnostics);
        self::assertSame(15, $headlessDiagnostics[0]['range']['start']['character']);
    }

    public function testRuntimeReadySavedDiagnosticsMatchTheFinalLspPublicationAfterRefresh(): void
    {
        $root = realpath(\dirname(__DIR__).'/Fixtures/RuntimeApplication');
        self::assertIsString($root);
        $path = $root.'/templates/components/Search.html.twig';
        $text = file_get_contents($path);
        self::assertIsString($text);
        try {
            $lspDiagnostics = $this->publishedDiagnostics(
                $root,
                'file://'.$path,
                'twig',
                $text,
                ['workspaceTrust' => true],
            );
            $headlessDiagnostics = $this->headlessDiagnostics($root, 'templates/components/Search.html.twig', false);

            $this->assertSameDiagnostics($lspDiagnostics, $headlessDiagnostics);
            self::assertSame('stimulus.unknown_controller', $headlessDiagnostics[0]['code']);
        } finally {
            (new Filesystem())->remove($root.'/var/symfony-lsp/dev');
        }
    }

    /**
     * @param array<array-key, mixed> $initializationOptions
     *
     * @return list<ProtocolDiagnostic>
     */
    private function publishedDiagnostics(string $root, string $uri, string $languageId, string $text, array $initializationOptions): array
    {
        $output = new CapturingWritableStream();
        $initialize = $this->frame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'rootUri' => 'file://'.$root,
            'capabilities' => ['general' => ['positionEncodings' => ['utf-16']]],
            'initializationOptions' => $initializationOptions,
        ]]);
        $initialized = $this->frame(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []]);
        $refresh = $this->frame(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'workspace/executeCommand', 'params' => [
            'command' => 'symfony.refreshIndex',
        ]]);
        $open = $this->frame(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
            'textDocument' => [
                'uri' => $uri,
                'languageId' => $languageId,
                'version' => 1,
                'text' => $text,
            ],
        ]]);
        $shutdown = $this->frame(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'shutdown', 'params' => []]);
        $exit = $this->frame(['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []]);
        $input = new ReadableIterableStream((static function () use ($output, $initialize, $initialized, $refresh, $open, $shutdown, $exit, $uri): \Generator {
            yield $initialize;
            while (!str_contains($output->contents(), '"id":1')) {
                delay(0);
            }
            yield $initialized;
            yield $refresh;
            while (!str_contains($output->contents(), '"id":2')) {
                delay(0);
            }
            yield $open;
            while (!str_contains($output->contents(), 'textDocument/publishDiagnostics') || !str_contains($output->contents(), $uri)) {
                delay(0);
            }
            yield $shutdown;
            while (!str_contains($output->contents(), '"id":3')) {
                delay(0);
            }
            yield $exit;
        })());
        self::assertSame(0, (new LanguageServerFactory())->create($input, $output)->run());
        $publications = [];
        foreach ($this->decodeFrames($output->contents()) as $message) {
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
        $diagnostics = $params['diagnostics'] ?? null;
        self::assertIsArray($diagnostics);
        /* @var list<ProtocolDiagnostic> $diagnostics */

        return $diagnostics;
    }

    /** @return list<HeadlessDiagnostic> */
    private function headlessDiagnostics(string $root, string $path, bool $sourceOnly): array
    {
        $arguments = [
            '--format=json',
            '--workspace='.$root,
            $path,
        ];
        if ($sourceOnly) {
            array_unshift($arguments, '--source-only');
        }
        $execution = (new LanguageServerFactory())->createCheck()->run($arguments);
        self::assertSame(1, $execution->exitCode, $execution->stderr);
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

    /** @param array<array-key, mixed> $message */
    private function frame(array $message): string
    {
        $json = json_encode($message, \JSON_THROW_ON_ERROR);

        return 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
    }

    /** @return list<array<array-key, mixed>> */
    private function decodeFrames(string $frames): array
    {
        $transport = new ContentLengthJsonRpcTransport(
            new ReadableBuffer($frames),
            new CapturingWritableStream(),
        );
        $messages = [];
        while (null !== $message = $transport->receive()) {
            $decoded = json_decode($message, true, 512, \JSON_THROW_ON_ERROR);
            if (\is_array($decoded)) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }
}
