<?php

namespace Symfony\Lsp\Tests\Server;

use Amp\Process\Process;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;

final class ServerExecutableTest extends TestCase
{
    public function testReportsUnhandledServerFailuresToStandardError(): void
    {
        $environment = getenv();
        $root = \dirname(__DIR__, 2);
        $process = Process::start(
            [Path::join($root, 'bin/symfony-lsp')],
            workingDirectory: $root,
            environment: [...$environment, 'SYMFONY_LSP_TREE_SITTER' => \PHP_BINARY],
            options: ['bypass_shell' => true],
        );
        $futures = [
            'stdout' => async(static fn (): string => buffer($process->getStdout())),
            'stderr' => async(static fn (): string => buffer($process->getStderr())),
            'exitCode' => async(static fn (): int => $process->join()),
        ];

        $process->getStdin()->write("Broken\r\n\r\n");
        $process->getStdin()->end();
        /** @var array{stdout: string, stderr: string, exitCode: int} $result */
        $result = await($futures);

        self::assertSame(1, $result['exitCode']);
        self::assertSame('', $result['stdout']);
        self::assertMatchesRegularExpression(
            '{^Symfony LSP failed: .+ at (?:src|vendor)/.+:\d+: .+\n$}',
            $result['stderr'],
        );
        self::assertStringContainsString('A JSON-RPC message header is malformed.', $result['stderr']);
    }
}
