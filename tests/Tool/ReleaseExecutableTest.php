<?php

namespace Symfony\Lsp\Tests\Tool;

use Amp\Process\Process;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;

final class ReleaseExecutableTest extends TestCase
{
    public function testLoadsComposerDependencies(): void
    {
        $root = \dirname(__DIR__, 2);
        $process = Process::start(
            [\PHP_BINARY, Path::join($root, 'tools/release'), '0.0.0', '--yes'],
            workingDirectory: $root,
            environment: [...getenv(), 'PATH' => '/missing'],
            options: ['bypass_shell' => true],
        );
        $futures = [
            'stdout' => async(static fn (): string => buffer($process->getStdout())),
            'stderr' => async(static fn (): string => buffer($process->getStderr())),
            'exitCode' => async(static fn (): int => $process->join()),
        ];
        $process->getStdin()->close();

        /** @var array{stdout: string, stderr: string, exitCode: int} $result */
        $result = await($futures);

        self::assertSame(1, $result['exitCode']);
        self::assertSame('', $result['stdout']);
        self::assertSame("Required command not found: git.\n", $result['stderr']);
    }
}
