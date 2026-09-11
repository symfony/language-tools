<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tests\Support\TestWorkspace;
use Symfony\Lsp\Tools\InteractiveProcessRunner;

final class InteractiveProcessRunnerTest extends TestCase
{
    public function testInheritsTerminalStreams(): void
    {
        $workspace = new TestWorkspace();
        $path = $workspace->path('stdout-stat.json');
        $parent = fstat(\STDOUT);
        self::assertIsArray($parent);

        try {
            $status = (new InteractiveProcessRunner())->run([
                \PHP_BINARY,
                '-r',
                'file_put_contents($argv[1], json_encode(fstat(STDOUT), JSON_THROW_ON_ERROR));',
                $path,
            ], __DIR__);
            /** @var array{dev: int, ino: int} $child */
            $child = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

            self::assertSame(0, $status);
            self::assertSame($parent['dev'], $child['dev']);
            self::assertSame($parent['ino'], $child['ino']);
        } finally {
            $workspace->cleanup();
        }
    }

    public function testKeepsRedirectedFileOutputInOrder(): void
    {
        $workspace = new TestWorkspace();
        $script = $workspace->write('run.php', \sprintf(<<<'PHP'
            <?php
            require %s;

            $runner = new Symfony\Lsp\Tools\InteractiveProcessRunner();
            $child = [PHP_BINARY, '-r', 'for ($i = 0; $i < 400; ++$i) { echo "child-$argv[1]-$i\n"; }'];
            fwrite(STDOUT, "first\n");
            $runner->run([...$child, 'one']);
            fwrite(STDOUT, "second\n");
            fwrite(STDERR, "error\n");
            $runner->run([...$child, 'two']);
            fwrite(STDOUT, "third\n");
            PHP, var_export(Path::join(\dirname(__DIR__, 2), 'vendor/autoload.php'), true)));
        $log = $workspace->path('run.log');

        try {
            $redirection = fopen($log, 'w');
            self::assertIsResource($redirection);
            $process = proc_open(
                [\PHP_BINARY, $script],
                [['file', '/dev/null', 'r'], $redirection, $redirection],
                $pipes,
            );
            self::assertIsResource($process);
            self::assertSame(0, proc_close($process));
            fclose($redirection);

            $lines = explode("\n", trim((string) file_get_contents($log)));

            self::assertSame('first', $lines[0]);
            self::assertSame('child-one-0', $lines[1]);
            self::assertSame('child-one-399', $lines[400]);
            self::assertSame(['second', 'error'], \array_slice($lines, 401, 2));
            self::assertSame('child-two-0', $lines[403]);
            self::assertSame('child-two-399', $lines[802]);
            self::assertSame('third', $lines[803]);
            self::assertCount(804, $lines);
        } finally {
            $workspace->cleanup();
        }
    }
}
