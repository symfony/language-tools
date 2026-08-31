<?php

namespace Symfony\Lsp\Tests\Support;

use PHPUnit\Framework\TestCase;

final class ExecutableRunnerTest extends TestCase
{
    public function testCapturesStandardOutputErrorOutputAndExitCodeConcurrently(): void
    {
        $result = (new ExecutableRunner())->run([
            \PHP_BINARY,
            '-r',
            'fwrite(STDOUT, str_repeat("o", 200000)); fwrite(STDERR, str_repeat("e", 200000)); exit(7);',
        ]);

        self::assertSame(7, $result->exitCode);
        self::assertSame(200000, \strlen($result->stdout));
        self::assertSame(200000, \strlen($result->stderr));
    }
}
