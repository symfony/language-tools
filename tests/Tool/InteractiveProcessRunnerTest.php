<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
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
}
