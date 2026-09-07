<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\NativeProcessRunner;
use Symfony\Lsp\Tools\Dogfood\ProcessResult;
use Symfony\Lsp\Tools\Dogfood\ScenarioRunner;

/**
 * @phpstan-import-type ScenarioReport from ScenarioRunner
 *
 * @phpstan-type HarnessReport array{project: string, environment: string, manifestRevision: string, serverVersion: string|null, status: array<array-key, mixed>|null, terminal: bool, outcome: string, error: string|null, scenarioCount: int, scenarios: list<ScenarioReport>, requestCount: int, assertionFailures: int, violations: list<array{scenario: string, method: string, message: string}>, transportFailure: string|null, serverError: string|null, exitCode: int|null, runtimeBridgeTimings: array<array-key, mixed>|null, timings: array<string, float|int|null>}
 */
final class DogfoodServerTest extends TestCase
{
    private const CONTROLLER = <<<'PHP'
        <?php

        namespace App\Controller;

        final class HelloController
        {
            public function index(): string
            {
                return $this->render('hello/index.html.twig');
            }
        }

        PHP;

    private string $directory;
    private string $project;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-dogfood-server-'.bin2hex(random_bytes(8)));
        $this->project = Path::join($this->directory, 'project');
        (new Filesystem())->dumpFile(Path::join($this->project, 'src/Controller/HelloController.php'), self::CONTROLLER);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testRefusesToRunWithoutAScenarioManifest(): void
    {
        $server = new ScriptedLanguageServer($this->directory, []);
        $result = $this->execute([$server->path, $this->project]);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('--scenarios=FILE', $result->errorOutput);
        self::assertSame('', $result->standardOutput);
        self::assertFalse($server->started());
    }

    public function testRejectsAnUnusableManifestBeforeStartingTheServer(): void
    {
        $server = new ScriptedLanguageServer($this->directory, []);
        $manifest = Path::join($this->directory, 'empty.json');
        (new Filesystem())->dumpFile($manifest, json_encode([
            'version' => 1,
            'revision' => str_repeat('a', 40),
            'scenarios' => [],
            'diagnostics' => [],
        ], \JSON_THROW_ON_ERROR));
        $result = $this->execute(['--scenarios='.$manifest, $server->path, $this->project]);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('must be a non-empty list of scenarios', $result->errorOutput);
        self::assertFalse($server->started());
    }

    public function testRejectsAManifestReviewedForAnotherRevision(): void
    {
        $server = new ScriptedLanguageServer($this->directory, []);
        $result = $this->execute([
            '--scenarios='.$this->manifest(),
            '--revision='.str_repeat('b', 40),
            $server->path,
            $this->project,
        ]);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('is pinned to "'.str_repeat('b', 40).'"', $result->errorOutput);
        self::assertFalse($server->started());
    }

    public function testReportsPassingScenarios(): void
    {
        $server = new ScriptedLanguageServer($this->directory, ['responses' => [
            ['method' => 'textDocument/completion', 'result' => [['label' => 'hello/index.html.twig']]],
        ]]);
        $result = $this->execute(['--scenarios='.$this->manifest(), $server->path, $this->project]);
        $report = $this->report($result);

        self::assertSame(0, $result->exitCode, $result->errorOutput);
        self::assertSame('passed', $report['outcome']);
        self::assertNull($report['error']);
        self::assertNull($report['transportFailure']);
        self::assertSame(str_repeat('a', 40), $report['manifestRevision']);
        self::assertSame('test', $report['serverVersion']);
        self::assertTrue($report['terminal']);
        self::assertSame(1, $report['scenarioCount']);
        self::assertSame(0, $report['assertionFailures']);
        self::assertSame([], $report['violations']);
        self::assertGreaterThan(0, $report['requestCount']);
        self::assertSame(0, $report['exitCode']);
        $scenario = $report['scenarios'][0];
        self::assertSame('hello.completion', $scenario['id']);
        self::assertSame('pass', $scenario['status']);
        self::assertSame([['phase' => 'baseline', 'method' => 'completion', 'status' => 'pass']], array_map(
            static fn (array $check): array => ['phase' => $check['phase'], 'method' => $check['method'], 'status' => $check['status']],
            $scenario['checks'],
        ));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $scenario['checks'][0]['fingerprint']);
        self::assertSame([
            'startupMilliseconds',
            'initializeMilliseconds',
            'sourceIndexMilliseconds',
            'runtimeIndexMilliseconds',
            'indexWaitMilliseconds',
            'scenariosMilliseconds',
            'shutdownMilliseconds',
            'totalMilliseconds',
        ], array_keys($report['timings']));
        foreach ($report['timings'] as $milliseconds) {
            self::assertTrue(\is_int($milliseconds) || \is_float($milliseconds));
            self::assertGreaterThanOrEqual(0.0, (float) $milliseconds);
        }
    }

    public function testKeepsTheProcessSuccessfulWhenScenariosFail(): void
    {
        $server = new ScriptedLanguageServer($this->directory, ['responses' => [
            ['method' => 'textDocument/completion', 'result' => [['label' => 'other/template.html.twig']]],
        ]]);
        $result = $this->execute(['--scenarios='.$this->manifest(), $server->path, $this->project]);
        $report = $this->report($result);

        self::assertSame(0, $result->exitCode, $result->errorOutput);
        self::assertSame('failed', $report['outcome']);
        self::assertGreaterThan(0, $report['assertionFailures']);
        self::assertSame('fail', $report['scenarios'][0]['checks'][0]['status']);
        self::assertNotEmpty($report['scenarios'][0]['checks'][0]['failures']);
    }

    public function testReportsEveryExpectedCheckWhenTheServerNeverAnswers(): void
    {
        $server = Path::join($this->directory, 'silent-server');
        file_put_contents($server, "#!/usr/bin/env php\n<?php\n\nsleep(10);\n");
        chmod($server, 0755);
        $result = $this->execute(['--scenarios='.$this->manifest(), $server, $this->project], timeout: 20.0);
        $report = $this->report($result);

        self::assertSame(1, $result->exitCode);
        self::assertSame('aborted', $report['outcome']);
        self::assertIsString($report['error']);
        self::assertSame(1, $report['scenarioCount']);
        self::assertSame('error', $report['scenarios'][0]['status']);
        self::assertSame('error', $report['scenarios'][0]['checks'][0]['status']);
        self::assertNull($report['scenarios'][0]['checks'][0]['fingerprint']);
        self::assertNotEmpty($report['scenarios'][0]['checks'][0]['failures']);
    }

    public function testTerminatesTheServerWhenProtocolParsingFails(): void
    {
        $lockPath = Path::join($this->directory, 'server.lock');
        $server = Path::join($this->directory, 'malformed-server');
        file_put_contents($server, <<<'PHP'
            #!/usr/bin/env php
            <?php

            $lock = fopen(__DIR__.'/server.lock', 'c+');
            flock($lock, LOCK_EX);
            fwrite(STDOUT, "Content-Length: 1\r\n\r\n{");
            fflush(STDOUT);
            sleep(10);
            PHP);
        chmod($server, 0755);

        $result = $this->execute(['--scenarios='.$this->manifest(), $server, $this->project]);

        self::assertSame(1, $result->exitCode);
        self::assertFalse($result->timedOut, $result->errorOutput);
        self::assertSame('aborted', $this->report($result)['outcome']);
        $lock = fopen($lockPath, 'c+');
        self::assertIsResource($lock);
        try {
            self::assertTrue(flock($lock, \LOCK_EX | \LOCK_NB));
        } finally {
            fclose($lock);
        }
    }

    public function testCapturesLargeServerErrorOutputWithoutBlockingProtocolResponses(): void
    {
        $server = new ScriptedLanguageServer($this->directory, [
            'noise' => str_repeat('x', 1000000),
            'responses' => [['method' => 'textDocument/completion', 'result' => [['label' => 'hello/index.html.twig']]]],
        ]);
        $result = $this->execute(['--scenarios='.$this->manifest(), $server->path, $this->project]);
        $report = $this->report($result);

        self::assertSame(0, $result->exitCode, $result->errorOutput);
        self::assertSame('passed', $report['outcome']);
        self::assertSame(0, $report['exitCode']);
        self::assertNull($report['runtimeBridgeTimings']);
        self::assertIsString($report['serverError']);
        self::assertSame(1000000, \strlen($report['serverError']));
    }

    private function manifest(): string
    {
        $path = Path::join($this->directory, 'scenarios.json');
        (new Filesystem())->dumpFile($path, json_encode([
            'version' => 1,
            'revision' => str_repeat('a', 40),
            'scenarios' => [[
                'id' => 'hello.completion',
                'file' => 'src/Controller/HelloController.php',
                'anchor' => "'hello/index.html.twig'",
                'offset' => 1,
                'expect' => ['completion' => ['equals' => ['hello/index.html.twig']]],
            ]],
            'diagnostics' => [],
        ], \JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @param list<string> $arguments
     */
    private function execute(array $arguments, float $timeout = 10.0): ProcessResult
    {
        return (new NativeProcessRunner())->run([
            \PHP_BINARY,
            Path::join(\dirname(__DIR__, 3), 'tools/dogfood-server'),
            '--index-timeout=1',
            '--request-timeout=2',
            ...$arguments,
        ], timeout: $timeout);
    }

    /**
     * @return HarnessReport
     */
    private function report(ProcessResult $result): array
    {
        $decoded = json_decode($result->standardOutput, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame([
            'project', 'environment', 'manifestRevision', 'serverVersion', 'status', 'terminal', 'outcome', 'error',
            'scenarioCount', 'scenarios', 'requestCount', 'assertionFailures', 'violations', 'transportFailure',
            'serverError', 'exitCode', 'runtimeBridgeTimings', 'timings',
        ], array_keys($decoded));
        /** @var HarnessReport $report */
        $report = $decoded;

        return $report;
    }
}
