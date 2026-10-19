<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Runtime\NativeProcessRunner;

final class BridgeTranslationsTest extends TestCase
{
    public function testCollectsMessagesFromTheParentLocaleCatalogue(): void
    {
        $project = realpath(__DIR__.'/../Fixtures/RuntimeApplication');
        self::assertIsString($project);

        $process = (new NativeProcessRunner(30.0))->run([
            \PHP_BINARY,
            \dirname(__DIR__, 2).'/resources/bridge.php',
            '--project='.$project,
            '--environment=regional_locale',
            '--debug=1',
            '--sections=translations',
            '--rebuild-container=1',
        ], $project);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $snapshot = json_decode($process->stdout, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);
        self::assertSame([], $snapshot['errors'] ?? null, $process->stdout);
        self::assertIsArray($snapshot['sections'] ?? null);
        $translations = $snapshot['sections']['translations'] ?? null;
        self::assertIsArray($translations);
        self::assertIsArray($translations['items'] ?? null);
        self::assertContains([
            'key' => 'fixture.message',
            'domain' => 'messages',
            'locale' => 'en',
            'message' => 'Fixture translation',
            'icu' => false,
        ], $translations['items']);
    }
}
