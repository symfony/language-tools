<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Runtime\NativeProcessRunner;

final class BridgeConfigurationCompilationTest extends TestCase
{
    #[DataProvider('configurationSectionOrderProvider')]
    public function testUsesValidatedTypedEnvironmentConfigurationAcrossSectionOrders(string $sections): void
    {
        $project = realpath(__DIR__.'/../Fixtures/RuntimeApplication');
        self::assertIsString($project);
        $process = (new NativeProcessRunner(30.0))->run([
            \PHP_BINARY,
            \dirname(__DIR__, 2).'/resources/bridge.php',
            '--project='.$project,
            '--environment=test',
            '--debug=1',
            '--sections='.$sections,
            '--rebuild-container=1',
        ], $project);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $snapshot = json_decode($process->stdout, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);
        self::assertSame([], $snapshot['errors'] ?? null, $process->stdout);
        $sections = $snapshot['sections'] ?? null;
        self::assertIsArray($sections);
        $messenger = $sections['messenger'] ?? null;
        self::assertIsArray($messenger);
        self::assertSame([], $messenger['warnings'] ?? null, $process->stdout);
        $messages = $messenger['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertContains(['class' => 'App\\Message\\Ping', 'transports' => ['async']], $messages);
    }

    /** @return iterable<string, array{string}> */
    public static function configurationSectionOrderProvider(): iterable
    {
        yield 'security before messenger' => ['security,messenger'];
        yield 'messenger before security' => ['messenger,security'];
    }

    public function testFallsBackToStructuredConfigurationWhenEffectiveExtractionFails(): void
    {
        $project = realpath(__DIR__.'/../Fixtures/RuntimeApplication');
        self::assertIsString($project);
        $bridge = \dirname(__DIR__, 2).'/resources/bridge.php';
        $runner = new NativeProcessRunner(30.0);
        $warmup = $runner->run([
            \PHP_BINARY,
            $bridge,
            '--project='.$project,
            '--environment=test',
            '--debug=1',
            '--sections=routes',
            '--rebuild-container=1',
        ], $project);
        self::assertSame(0, $warmup->exitCode, $warmup->stderr."\n".$warmup->stdout);

        $failure = sys_get_temp_dir().'/symfony-lsp-configuration-failure-'.bin2hex(random_bytes(8));
        $previousFailure = getenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_FAILURE');
        putenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_FAILURE='.$failure);
        try {
            $process = $runner->run([
                \PHP_BINARY,
                $bridge,
                '--project='.$project,
                '--environment=test',
                '--debug=1',
                '--sections=messenger',
            ], $project);
        } finally {
            if (false === $previousFailure) {
                putenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_FAILURE');
            } else {
                putenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_FAILURE='.$previousFailure);
            }
        }

        try {
            self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
            self::assertStringNotContainsString('CANARY_SECRET_', $process->stdout);
            $snapshot = json_decode($process->stdout, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($snapshot);
            self::assertSame(['status' => 'valid'], $snapshot['configurationValidation'] ?? null);
            self::assertSame([], $snapshot['errors'] ?? null, $process->stdout);
            $sections = $snapshot['sections'] ?? null;
            self::assertIsArray($sections);
            $messenger = $sections['messenger'] ?? null;
            self::assertIsArray($messenger);
            self::assertSame([], $messenger['warnings'] ?? null, $process->stdout);
            self::assertSame(["failed\n"], file($failure));
        } finally {
            @unlink($failure);
        }
    }

    public function testCompilesEffectiveConfigurationOnceAcrossSections(): void
    {
        $project = realpath(__DIR__.'/../Fixtures/RuntimeApplication');
        self::assertIsString($project);
        $bridge = \dirname(__DIR__, 2).'/resources/bridge.php';
        $runner = new NativeProcessRunner(30.0);
        $warmup = $runner->run([
            \PHP_BINARY,
            $bridge,
            '--project='.$project,
            '--environment=test',
            '--debug=1',
            '--sections=routes',
        ], $project);
        self::assertSame(0, $warmup->exitCode, $warmup->stderr."\n".$warmup->stdout);

        $counter = sys_get_temp_dir().'/symfony-lsp-configuration-compile-'.bin2hex(random_bytes(8));
        $previousCounter = getenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_COUNTER');
        putenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_COUNTER='.$counter);
        try {
            $process = $runner->run([
                \PHP_BINARY,
                $bridge,
                '--project='.$project,
                '--environment=test',
                '--debug=1',
                '--sections=twig_components,messenger,assets,security,stimulus',
            ], $project);
        } finally {
            if (false === $previousCounter) {
                putenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_COUNTER');
            } else {
                putenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_COUNTER='.$previousCounter);
            }
        }

        try {
            self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
            $snapshot = json_decode($process->stdout, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($snapshot);
            self::assertSame([], $snapshot['errors'] ?? null, $process->stdout);
            self::assertSame(
                ['twig_components', 'messenger', 'assets', 'security', 'stimulus'],
                array_keys(\is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : []),
            );
            self::assertSame(["compiled\n"], file($counter));
        } finally {
            @unlink($counter);
        }
    }
}
