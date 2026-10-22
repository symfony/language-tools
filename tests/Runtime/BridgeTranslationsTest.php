<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('refreshProvider')]
    public function testRefreshesReloadCataloguesEditedInTheSecondTheyWereCached(string $option): void
    {
        $project = realpath(__DIR__.'/../Fixtures/RuntimeApplication');
        self::assertIsString($project);
        $warmup = $this->runBridge($project, []);
        self::assertNotContains('fixture.same_second', $this->keys($warmup));
        $catalogues = glob($project.'/var/cache/test/translations/catalogue.en.*.php') ?: [];
        self::assertNotSame([], $catalogues);
        $file = $project.'/translations/messages.en.yaml';
        $original = file_get_contents($file);
        self::assertIsString($original);

        try {
            file_put_contents($file, $original."\nfixture.same_second: Same second\n");
            touch($file, (int) filemtime($catalogues[0]));
            $process = $this->runBridge($project, [$option]);
        } finally {
            file_put_contents($file, $original);
        }

        self::assertContains('fixture.same_second', $this->keys($process));
        self::assertNotContains('fixture.same_second', $this->keys($this->runBridge($project, [$option])));
    }

    /** @return iterable<string, array{string}> */
    public static function refreshProvider(): iterable
    {
        yield 'container rebuild' => ['--rebuild-container=1'];
        yield 'targeted refresh' => ['--targeted-refresh=1'];
    }

    /** @param list<string> $options */
    private function runBridge(string $project, array $options): string
    {
        $process = (new NativeProcessRunner(30.0))->run([
            \PHP_BINARY,
            \dirname(__DIR__, 2).'/resources/bridge.php',
            '--project='.$project,
            '--environment=test',
            '--debug=1',
            '--sections=translations',
            ...$options,
        ], $project);
        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);

        return $process->stdout;
    }

    /** @return list<mixed> */
    private function keys(string $stdout): array
    {
        $snapshot = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);
        self::assertSame([], $snapshot['errors'] ?? null, $stdout);
        self::assertIsArray($snapshot['sections'] ?? null);
        $translations = $snapshot['sections']['translations'] ?? null;
        self::assertIsArray($translations);
        $items = $translations['items'] ?? null;
        self::assertIsArray($items);

        return array_column($items, 'key');
    }
}
