<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\NativeProcessRunner;

final class NativeProcessRunnerTest extends TestCase
{
    public function testOverridesTheInheritedEnvironment(): void
    {
        $result = (new NativeProcessRunner())->run(
            [\PHP_BINARY, '-r', 'fwrite(STDOUT, (string) getenv("SYMFONY_LSP_DOGFOOD_TEST"));'],
            environment: ['SYMFONY_LSP_DOGFOOD_TEST' => 'configured'],
        );

        self::assertTrue($result->successful());
        self::assertSame('configured', $result->standardOutput);
    }
}
