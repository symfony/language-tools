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

    public function testDrainsStandardOutputAndErrorConcurrently(): void
    {
        $result = (new NativeProcessRunner(maximumErrorOutputBytes: 1000000))->run([
            \PHP_BINARY,
            '-r',
            'fwrite(STDOUT, str_repeat("a", 1000000)); fwrite(STDERR, str_repeat("b", 1000000));',
        ], __DIR__);

        self::assertSame(1000000, \strlen($result->stdout()));
        self::assertSame(1000000, \strlen($result->stderr()));
    }

    public function testEnforcesStandardOutputLimit(): void
    {
        $runner = new NativeProcessRunner(maximumOutputBytes: 8);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('output limit');

        $runner->run([\PHP_BINARY, '-r', 'fwrite(STDOUT, "123456789");'], __DIR__);
    }

    public function testTruncatesErrorOutputWithoutStoppingTheProcess(): void
    {
        $runner = new NativeProcessRunner(maximumOutputBytes: 8, maximumErrorOutputBytes: 4);

        $result = $runner->run([
            \PHP_BINARY,
            '-r',
            'fwrite(STDERR, "123456789"); fwrite(STDOUT, "payload");',
        ], __DIR__);

        self::assertSame(0, $result->exitCode());
        self::assertSame('payload', $result->stdout());
        self::assertSame('1234', $result->stderr());
    }

    public function testReportsStartFailures(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to start the project bridge.');

        (new NativeProcessRunner())->run([\PHP_BINARY, '-r', ''], __DIR__.'/missing');
    }

    public function testEnforcesTheOperationTimeout(): void
    {
        $runner = new NativeProcessRunner();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The project bridge timed out after 0.01 seconds.');

        $runner->run([\PHP_BINARY, '-r', 'sleep(10);'], __DIR__, timeout: 0.01);
    }
}
