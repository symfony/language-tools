<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
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

    public function testCancelsAWorkingProcess(): void
    {
        $cancellation = new DeferredCancellation();
        EventLoop::delay(0.01, static function () use ($cancellation): void {
            $cancellation->cancel();
        });

        $this->expectException(CancelledException::class);

        (new NativeProcessRunner())->run([
            \PHP_BINARY,
            '-r',
            'sleep(10);',
        ], __DIR__, $cancellation->getCancellation());
    }

    public function testEnforcesOutputLimit(): void
    {
        $runner = new NativeProcessRunner(maximumOutputBytes: 2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('output limit');

        $runner->run([\PHP_BINARY, '-r', 'echo "large";'], __DIR__);
    }
}
