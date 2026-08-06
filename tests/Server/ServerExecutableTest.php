<?php

namespace Symfony\Lsp\Tests\Server;

use PHPUnit\Framework\TestCase;

final class ServerExecutableTest extends TestCase
{
    public function testReportsUnhandledServerFailuresToStandardError(): void
    {
        $process = proc_open(
            [\dirname(__DIR__, 2).'/bin/symfony-lsp'],
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w'],
            ],
            $pipes,
            \dirname(__DIR__, 2),
            [...getenv(), 'SYMFONY_LSP_TREE_SITTER' => \PHP_BINARY],
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);

        fwrite($pipes[0], "Broken\r\n\r\n");
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($process));
        self::assertSame('', $output);
        self::assertIsString($errorOutput);
        self::assertMatchesRegularExpression(
            '{^Symfony LSP failed: .+ at (?:src|vendor)/.+:\d+: .+\n$}',
            $errorOutput,
        );
        self::assertStringContainsString('A JSON-RPC message header is malformed.', $errorOutput);
    }
}
