<?php

namespace Symfony\Lsp\Tests\Server;

use Amp\Process\Process;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tests\Support\ContentLengthMessageCodec;
use Symfony\Lsp\Tests\Support\ExecutableRunner;

use function Amp\async;
use function Amp\ByteStream\buffer;

final class ServerExecutableTest extends TestCase
{
    public function testReportsUnhandledServerFailuresToStandardError(): void
    {
        $environment = getenv();
        $root = \dirname(__DIR__, 2);
        $result = (new ExecutableRunner())->run(
            [Path::join($root, 'bin/symfony-lsp')],
            $root,
            $environment,
            "Broken\r\n\r\n",
        );

        self::assertSame(1, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertMatchesRegularExpression(
            '{^Symfony Language Tools failed: .+ at (?:src|vendor)/.+:\d+: .+\n$}',
            $result->stderr,
        );
        self::assertStringContainsString('A JSON-RPC message header is malformed.', $result->stderr);
    }

    public function testReportsFatalErrorsToStandardError(): void
    {
        $environment = getenv();
        $root = \dirname(__DIR__, 2);
        $input = (new ContentLengthMessageCodec())->encode([
            'jsonrpc' => '2.0',
            'method' => 'initialized',
            'params' => ['junk' => array_fill(0, 6_000_000, 1)],
        ]);
        $result = (new ExecutableRunner())->run(
            [Path::join($root, 'bin/symfony-lsp')],
            $root,
            [...$environment, 'SYMFONY_LSP_MEMORY_LIMIT' => '24M'],
            $input,
        );

        self::assertSame(255, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertStringContainsString('Allowed memory size', $result->stderr);
    }

    public function testServesTheProtocolOverASocket(): void
    {
        $root = \dirname(__DIR__, 2);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsNotBool($listener, (string) $errorMessage);
        $address = (string) stream_socket_get_name($listener, false);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $process = Process::start(
            [Path::join($root, 'bin/symfony-lsp'), '--socket='.$port],
            workingDirectory: $root,
            environment: getenv(),
            options: ['bypass_shell' => true],
        );
        $stderr = async(static fn (): string => buffer($process->getStderr()));
        $connection = stream_socket_accept($listener, 10);
        self::assertIsNotBool($connection);
        stream_set_timeout($connection, 10);

        $codec = new ContentLengthMessageCodec();
        $initialize = $this->request($codec, $connection, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'processId' => null,
            'rootUri' => null,
            'capabilities' => new \stdClass(),
            'initializationOptions' => ['workspaceTrust' => false],
        ]]);
        $shutdown = $this->request($codec, $connection, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown', 'params' => []]);
        fwrite($connection, $codec->encode(['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []]));
        fclose($connection);
        fclose($listener);

        $stderrOutput = $stderr->await();
        self::assertIsString($stderrOutput);
        self::assertSame(0, $process->join(), $stderrOutput);
        self::assertSame(1, $initialize['id'] ?? null);
        $result = $initialize['result'] ?? null;
        self::assertIsArray($result);
        self::assertArrayHasKey('capabilities', $result);
        self::assertSame(2, $shutdown['id'] ?? null);
        self::assertArrayHasKey('result', $shutdown);
    }

    /**
     * @param resource             $connection
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function request(ContentLengthMessageCodec $codec, $connection, array $message): array
    {
        fwrite($connection, $codec->encode($message));

        return $codec->read($connection);
    }
}
