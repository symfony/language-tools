<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Runtime\NativeProcessRunner;

final class BridgeApplicationLoggingTest extends TestCase
{
    public function testKeepsDeprecationsRaisedWhileReadingTheApplicationOutOfItsLoggers(): void
    {
        $project = realpath(__DIR__.'/../Fixtures/RuntimeApplication');
        self::assertIsString($project);
        $marker = sys_get_temp_dir().'/symfony-lsp-compile-deprecation-'.bin2hex(random_bytes(8));
        $previous = getenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_DEPRECATION');
        putenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_DEPRECATION='.$marker);

        try {
            $process = (new NativeProcessRunner(30.0))->run([
                \PHP_BINARY,
                \dirname(__DIR__, 2).'/resources/bridge.php',
                '--project='.$project,
                '--environment=test',
                '--debug=1',
                '--sections=routes,configuration',
                '--rebuild-container=1',
            ], $project);
        } finally {
            if (false === $previous) {
                putenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_DEPRECATION');
            } else {
                putenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_DEPRECATION='.$previous);
            }
            $triggered = is_file($marker);
            @unlink($marker);
        }

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertTrue($triggered, 'The fixture did not trigger a deprecation while the bridge read the application.');
        self::assertStringNotContainsString('FIXTURE_COMPILE_DEPRECATION', $process->stderr);
        self::assertStringNotContainsString('Deprecated', $process->stderr);
    }
}
