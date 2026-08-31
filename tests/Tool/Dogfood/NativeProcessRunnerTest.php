<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\NativeProcessRunner;
use Symfony\Lsp\Tools\Dogfood\ProcessInterruptedException;

final class NativeProcessRunnerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-dogfood-process-'.bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testOverridesTheInheritedEnvironment(): void
    {
        $result = (new NativeProcessRunner())->run(
            [\PHP_BINARY, '-r', 'fwrite(STDOUT, (string) getenv("SYMFONY_LSP_DOGFOOD_TEST"));'],
            environment: ['SYMFONY_LSP_DOGFOOD_TEST' => 'configured'],
        );

        self::assertTrue($result->successful());
        self::assertSame('configured', $result->standardOutput);
    }

    public function testCommandsCannotOpenTheControllingTerminal(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('Windows processes do not use POSIX controlling terminals.');
        }

        $result = (new NativeProcessRunner())->run([
            \PHP_BINARY,
            '-r',
            '$tty = @fopen("/dev/tty", "r"); fwrite(STDOUT, false === $tty ? "unavailable" : "available");',
        ]);

        self::assertTrue($result->successful(), $result->errorOutput);
        self::assertSame('unavailable', $result->standardOutput);
    }

    public function testKillsDescendantsWhenTheProcessTimesOut(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || !\function_exists('pcntl_fork')) {
            self::markTestSkipped('Process group assertions require POSIX process control.');
        }

        $lockPath = Path::join($this->directory, 'timeout.lock');
        $result = (new NativeProcessRunner())->run([
            \PHP_BINARY,
            '-r',
            <<<'PHP'
                $lock = fopen($argv[1], 'c+');
                flock($lock, LOCK_EX);
                $child = pcntl_fork();
                if (0 === $child) {
                    sleep(30);
                    exit;
                }
                fwrite(STDOUT, 'ready');
                fflush(STDOUT);
                sleep(30);
                PHP,
            $lockPath,
        ], timeout: 1.0);

        self::assertTrue($result->timedOut);
        self::assertSame('ready', $result->standardOutput);
        $this->assertLockIsReleased($lockPath);
    }

    public function testKillsDescendantsWhenTheProcessIsInterrupted(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || !\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('Signal assertions require POSIX process control.');
        }

        $lockPath = Path::join($this->directory, 'interrupt.lock');
        try {
            (new NativeProcessRunner())->run([
                \PHP_BINARY,
                '-r',
                <<<'PHP'
                    $lock = fopen($argv[1], 'c+');
                    flock($lock, LOCK_EX);
                    $child = pcntl_fork();
                    if (0 === $child) {
                        sleep(30);
                        exit;
                    }
                    posix_kill((int) $argv[2], SIGINT);
                    sleep(30);
                    PHP,
                $lockPath,
                (string) getmypid(),
            ], timeout: 5.0);
            self::fail('The interrupted process did not throw.');
        } catch (ProcessInterruptedException $e) {
            self::assertSame(\SIGINT, $e->signal);
        }

        $this->assertLockIsReleased($lockPath);
    }

    private function assertLockIsReleased(string $path): void
    {
        $lock = fopen($path, 'c+');
        self::assertIsResource($lock);
        try {
            self::assertTrue(flock($lock, \LOCK_EX | \LOCK_NB));
        } finally {
            fclose($lock);
        }
    }
}
