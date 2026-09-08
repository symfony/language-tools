<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\ConfigurationException;
use Symfony\Lsp\Tools\Dogfood\ProcessResult;
use Symfony\Lsp\Tools\Dogfood\ProjectConfiguration;
use Symfony\Lsp\Tools\Dogfood\ServerHarness;

final class ServerHarnessTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-server-harness-'.bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /** @param array<string, mixed> $scenario */
    #[DataProvider('scenarioBudgetProvider')]
    public function testBudgetsOnlyDeclaredChecksAndEditingBarriers(array $scenario, float $expectedTimeout): void
    {
        $manifest = $this->directory.'/scenarios.json';
        file_put_contents($manifest, json_encode([
            'version' => 1, 'revision' => str_repeat('a', 40), 'scenarios' => [$scenario], 'diagnostics' => [],
        ], \JSON_THROW_ON_ERROR));
        $processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult(0, '{}', '', false));
        $configuration = new ProjectConfiguration(
            'application', 'https://example.com/application.git', str_repeat('a', 40), null, 'dev', 'composer', false, 20,
            requestTimeout: 3,
            environmentVariables: ['APP_ENV' => 'dev'],
            scenarioFile: $manifest,
        );

        $result = (new ServerHarness($processes, '/tools/dogfood-server', '/bin/symfony-lsp'))->run($configuration, $this->directory);

        self::assertSame($expectedTimeout, $processes->calls[0]['timeout']);
        self::assertContains('--scenarios='.$manifest, $processes->calls[0]['command']);
        self::assertSame(['APP_ENV' => 'dev'], $processes->calls[0]['environment']);
        self::assertGreaterThanOrEqual(0.0, $result->manifestMilliseconds);
        self::assertGreaterThanOrEqual(0.0, $result->processMilliseconds);
    }

    /** @return iterable<string, array{array<string, mixed>, float}> */
    public static function scenarioBudgetProvider(): iterable
    {
        $scenario = ['id' => 'route.twig', 'file' => 'templates/index.html.twig', 'anchor' => 'home', 'expect' => ['completion' => ['includes' => ['home']]]];
        yield 'one meaningful request' => [$scenario, 47.0];
        $scenario['expect']['definition'] = ['includes' => ['src/Controller.php:1:0-1:4']];
        yield 'two meaningful requests' => [$scenario, 50.0];
        $scenario['edit'] = [
            'before' => 'home', 'after' => 'hom', 'anchor' => 'hom',
            'expect' => ['diagnostics' => ['equals' => []], 'codeAction' => ['includes' => ['Add parameter']]],
            'applyCodeAction' => 'Add parameter', 'afterFix' => ['diagnostics' => ['equals' => []]],
        ];
        yield 'editing and restoration' => [$scenario, 98.0];
    }

    /** @param 'runtime'|'source-only' $analysisMode */
    #[DataProvider('analysisModeProvider')]
    public function testForwardsTheRequestedAnalysisMode(string $analysisMode, bool $expectedSourceOnly): void
    {
        $manifest = $this->directory.'/scenarios.json';
        file_put_contents($manifest, json_encode([
            'version' => 1, 'revision' => str_repeat('a', 40), 'diagnostics' => [],
            'scenarios' => [['id' => 'route.twig', 'file' => 'templates/index.html.twig', 'anchor' => 'home', 'expect' => ['completion' => ['includes' => ['home']]]]],
        ], \JSON_THROW_ON_ERROR));
        $processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult(0, '{}', '', false));
        $configuration = new ProjectConfiguration(
            'application', 'https://example.com/application.git', str_repeat('a', 40), null, 'dev', 'composer', false, 20,
            scenarioFile: $manifest,
            analysisMode: $analysisMode,
        );

        (new ServerHarness($processes, '/tools/dogfood-server', '/bin/symfony-lsp'))->run($configuration, $this->directory);

        self::assertSame($expectedSourceOnly, \in_array('--source-only', $processes->calls[0]['command'], true));
        self::assertSame(['/bin/symfony-lsp', $this->directory], \array_slice($processes->calls[0]['command'], -2));
    }

    /** @return iterable<string, array{'runtime'|'source-only', bool}> */
    public static function analysisModeProvider(): iterable
    {
        yield 'runtime' => ['runtime', false];
        yield 'source-only' => ['source-only', true];
    }

    public function testMissingManifestNeverStartsTheProcess(): void
    {
        $processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult(0, '{}', '', false));
        $configuration = new ProjectConfiguration('application', 'https://example.com/application.git', str_repeat('a', 40), null, 'dev', 'composer', false, 20, scenarioFile: $this->directory.'/missing.json');

        try {
            (new ServerHarness($processes, '/tools/dogfood-server', '/bin/symfony-lsp'))->run($configuration, $this->directory);
            self::fail('A manifest is required before starting a server.');
        } catch (ConfigurationException) {
            self::assertSame([], $processes->calls);
        }
    }
}
