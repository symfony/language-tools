<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Runtime\NativeProcessRunner;

final class NativeProcessRunnerTest extends TestCase
{
    public function testRunsArgumentArrayWithoutShellInterpolation(): void
    {
        $result = (new NativeProcessRunner())->run([
            \PHP_BINARY,
            '-r',
            'fwrite(STDOUT, $argv[1]); fwrite(STDERR, "warning");',
            'hello; exit 99',
        ], __DIR__);

        self::assertSame(0, $result->exitCode());
        self::assertSame('hello; exit 99', $result->stdout());
        self::assertSame('warning', $result->stderr());
    }

    public function testEnforcesOutputLimit(): void
    {
        $runner = new NativeProcessRunner(maximumOutputBytes: 2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('output limit');

        $runner->run([\PHP_BINARY, '-r', 'echo "large";'], __DIR__);
    }
}
