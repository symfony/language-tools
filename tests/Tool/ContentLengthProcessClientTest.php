<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\TestWorkspace;
use Symfony\Lsp\Tools\ContentLengthProcessClient;

final class ContentLengthProcessClientTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-content-length-client-');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testReadsFragmentedHeadersAndBodies(): void
    {
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ready' => true]];
        $json = json_encode($message, \JSON_THROW_ON_ERROR);
        $server = $this->server(<<<'PHP'
            $json = base64_decode($argv[1]);
            $frame = 'Content-Length: '.strlen($json)."\r\nContent-Type: application/vscode-jsonrpc; charset=utf-8\r\n\r\n".$json;
            foreach (str_split($frame, 3) as $fragment) {
                fwrite(STDOUT, $fragment);
                fflush(STDOUT);
                usleep(2000);
            }
            PHP);
        $client = new ContentLengthProcessClient([$server, base64_encode($json)], 1.0);

        try {
            self::assertSame($message, $client->read());
            self::assertSame(0, $client->close());
        } finally {
            $client->terminate();
        }
    }

    /** @param non-empty-string $fragment */
    #[DataProvider('incompleteFrameProvider')]
    public function testIncompleteFramesCannotExceedTheReadDeadline(string $fragment): void
    {
        $server = $this->server(<<<'PHP'
            fwrite(STDOUT, base64_decode($argv[1]));
            fflush(STDOUT);
            sleep(5);
            PHP);
        $client = new ContentLengthProcessClient([$server, base64_encode($fragment)], 1.0);
        $startedAt = microtime(true);

        try {
            $client->read(0.1);
            self::fail('The incomplete frame should have timed out.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Timed out waiting for a Content-Length message.', $exception->getMessage());
            self::assertLessThan(0.5, microtime(true) - $startedAt);
        } finally {
            $client->terminate();
        }
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function incompleteFrameProvider(): iterable
    {
        yield 'partial header' => ['Content-Len'];
        yield 'partial body' => ["Content-Length: 30\r\n\r\n{\"jsonrpc\":\"2.0\""];
    }

    public function testRejectsJsonArrays(): void
    {
        $json = json_encode(['value'], \JSON_THROW_ON_ERROR);
        $server = $this->server(<<<'PHP'
            $json = base64_decode($argv[1]);
            fwrite(STDOUT, 'Content-Length: '.strlen($json)."\r\n\r\n".$json);
            fflush(STDOUT);
            PHP);
        $client = new ContentLengthProcessClient([$server, base64_encode($json)], 1.0);

        try {
            $client->read();
            self::fail('A JSON array is not a protocol message.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The Content-Length response body must contain a JSON object with string keys.', $exception->getMessage());
        } finally {
            $client->terminate();
        }
    }

    public function testParsingFailuresAllowFinallyCleanupToTerminateTheChild(): void
    {
        $lockPath = $this->workspace->path('server.lock');
        $readyPath = $this->workspace->path('server.ready');
        $server = $this->server(<<<'PHP'
            $lock = fopen($argv[1], 'c+');
            flock($lock, LOCK_EX);
            touch($argv[2]);
            fwrite(STDOUT, "Content-Length: 1\r\n\r\n{");
            fflush(STDOUT);
            sleep(5);
            PHP);
        $client = new ContentLengthProcessClient([$server, $lockPath, $readyPath], 1.0);
        $deadline = microtime(true) + 1.0;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($readyPath);

        try {
            $client->read();
            self::fail('The malformed response should have failed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Invalid JSON in the Content-Length response body.', $exception->getMessage());
        } finally {
            $client->terminate();
        }

        $lock = fopen($lockPath, 'c+');
        self::assertIsResource($lock);
        try {
            self::assertTrue(flock($lock, \LOCK_EX | \LOCK_NB));
        } finally {
            fclose($lock);
        }
    }

    private function server(string $body): string
    {
        return $this->workspace->executable(
            'server-'.bin2hex(random_bytes(4)),
            "#!/usr/bin/env php\n<?php\n".$body."\n",
        );
    }
}
