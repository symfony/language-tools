<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\ExecutableRunner;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class SmokeTestServerExecutableTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-smoke-executable-');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testCommandOnlyModeValidatesAPharThroughPhp(): void
    {
        $result = $this->runSmoke(['--commands-only', '--php', '--php-option=memory_limit=128M', $this->server(), '1.2.3-rc.1']);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringContainsString('Executable command dispatch passed', $result->stdout);
        self::assertStringContainsString("Symfony Language Tools command smoke test passed.\n", $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testRejectsSocketModeForCommandOnlyChecks(): void
    {
        $result = $this->runSmoke(['--socket', '--commands-only', $this->server()]);

        self::assertSame(2, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertStringStartsWith('Usage: smoke-test-server ', $result->stderr);
    }

    #[DataProvider('transportProvider')]
    public function testPreservesTheCompleteServerSmokeTest(bool $socketMode): void
    {
        $result = $this->runSmoke([...($socketMode ? ['--socket'] : []), $this->server(), '1.2.3-rc.1']);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringContainsString('Symfony Language Tools smoke test passed.', $result->stdout);
        self::assertStringContainsString('Headless diagnostics check passed', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    /** @return iterable<string, array{bool}> */
    public static function transportProvider(): iterable
    {
        yield 'standard input and output' => [false];
        yield 'Windows socket transport' => [true];
    }

    private function server(): string
    {
        return $this->workspace->executable('fake-server.php', <<<'PHP'
            #!/usr/bin/env php
            <?php

            $argument = $argv[1] ?? null;
            if ('--version' === $argument) {
                fwrite(STDOUT, "Symfony Language Tools 1.2.3-rc.1\r\n");
                exit(0);
            }
            if ('unknown-command' === $argument) {
                fwrite(STDERR, "Unknown command \"unknown-command\".\r\n");
                exit(11);
            }
            if ('check' === $argument) {
                fwrite(STDOUT, json_encode([
                    'tool' => ['version' => '1.2.3-rc.1'],
                    'complete' => true,
                    'diagnostics' => [['code' => 'env.malformed_chain']],
                ], JSON_THROW_ON_ERROR));
                exit(10);
            }

            $input = STDIN;
            $output = STDOUT;
            if (is_string($argument) && preg_match('/^--socket=(\d+)$/', $argument, $matches)) {
                $socket = stream_socket_client('tcp://127.0.0.1:'.$matches[1]);
                if (false === $socket) {
                    exit(1);
                }
                $input = $socket;
                $output = $socket;
            }

            while (true) {
                $header = '';
                while (!str_ends_with($header, "\r\n\r\n")) {
                    $line = fgets($input);
                    if (false === $line) {
                        exit(1);
                    }
                    $header .= $line;
                }
                if (1 !== preg_match('/Content-Length:\s*(\d+)/i', $header, $matches)) {
                    exit(1);
                }
                $body = '';
                while (strlen($body) < (int) $matches[1]) {
                    $body .= fread($input, (int) $matches[1] - strlen($body));
                }
                $message = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                $method = $message['method'] ?? null;
                if ('initialize' === $method) {
                    respond($output, [
                        'jsonrpc' => '2.0',
                        'id' => $message['id'],
                        'result' => [
                            'capabilities' => [],
                            'serverInfo' => ['version' => '1.2.3-rc.1'],
                        ],
                    ]);
                } elseif ('shutdown' === $method) {
                    respond($output, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
                } elseif ('exit' === $method) {
                    exit(0);
                }
            }

            function respond($output, array $message): void
            {
                $json = json_encode($message, JSON_THROW_ON_ERROR);
                fwrite($output, 'Content-Length: '.strlen($json)."\r\n\r\n".$json);
                fflush($output);
            }
            PHP);
    }

    /** @param list<string> $arguments */
    private function runSmoke(array $arguments): \Symfony\Lsp\Tests\Support\ProcessResult
    {
        $root = \dirname(__DIR__, 2);

        return (new ExecutableRunner())->run(
            [\PHP_BINARY, $root.'/tools/smoke-test-server', ...$arguments],
            $root,
            [...getenv(), 'SYMFONY_LSP_SMOKE_DEADLINE' => '2'],
        );
    }
}
