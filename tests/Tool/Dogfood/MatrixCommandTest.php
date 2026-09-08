<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Runtime\RuntimeBridgeTimingNormalizer;
use Symfony\Lsp\Tools\Dogfood\ComposerSetup;
use Symfony\Lsp\Tools\Dogfood\DiagnosticCheckHarness;
use Symfony\Lsp\Tools\Dogfood\HarnessInterface;
use Symfony\Lsp\Tools\Dogfood\HarnessResult;
use Symfony\Lsp\Tools\Dogfood\MatrixCommand;
use Symfony\Lsp\Tools\Dogfood\ProcessResult;
use Symfony\Lsp\Tools\Dogfood\ProjectConfiguration;
use Symfony\Lsp\Tools\Dogfood\ProvisioningException;
use Symfony\Lsp\Tools\Dogfood\RunClassifier;
use Symfony\Lsp\Tools\Dogfood\SetupRegistry;

use function Amp\delay;

final class MatrixCommandTest extends TestCase
{
    private string $directory;
    private string $checkout;
    private string $output;

    /** @var list<string> */
    private array $lines = [];

    /** @var array<string, mixed> */
    private array $diagnosticReport = [];

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-dogfood-'.bin2hex(random_bytes(8)));
        $this->checkout = Path::join($this->directory, 'checkout');
        $this->output = Path::join($this->directory, 'output');
        $this->diagnosticReport = [
            'schemaVersion' => 1,
            'complete' => true,
            'projects' => [['complete' => true, 'analysis' => ['mode' => 'runtime'], 'source' => ['state' => 'ready'], 'runtime' => ['state' => 'ready']]],
            'diagnostics' => [],
            'errors' => [],
            'baseline' => ['path' => null, 'mode' => 'none', 'stale' => []],
            'profile' => ['projects' => [['files' => 20]]],
        ];
        (new Filesystem())->mkdir($this->checkout);
        file_put_contents(Path::join($this->checkout, 'composer.json'), '{}');
        file_put_contents(Path::join($this->checkout, 'composer.lock'), json_encode([
            'packages' => [['name' => 'symfony/framework-bundle', 'version' => 'v8.1.0']],
            'packages-dev' => [],
        ], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testRunsColdAndWarmAndRecordsArtifacts(): void
    {
        $provisioner = new FakeProvisioner($this->checkout);
        $harness = new FakeHarness($this->successfulRun(), $this->successfulRun());

        $exitCode = $this->command($provisioner, $harness)->run([$this->configuration()], $this->output);

        self::assertSame(0, $exitCode);
        self::assertSame([$this->checkout, $this->checkout], $harness->applicationRoots);
        self::assertSame(['acme'], $provisioner->released);
        self::assertFileExists(Path::join($this->output, 'acme/cold.json'));
        self::assertFileExists(Path::join($this->output, 'acme/warm.json'));
        $report = $this->readReport();
        self::assertTrue($report['ok']);
        $recorded = $this->readJson($this->output.'/acme/project.json');
        self::assertIsString($recorded['expectationFingerprint'] ?? null);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $recorded['expectationFingerprint']);
        self::assertSame('8.1.0', $report['frameworkBundle']);
        self::assertNotEmpty($report['dependencies']['composerLockSha256']);
        self::assertSame(['modified' => [], 'untracked' => 1], $report['workingTree']);
        self::assertSame([], $report['cold']['layers'] ?? null);
        self::assertSame([], $report['warm']['layers'] ?? null);
        self::assertSame(['provisionMilliseconds', 'setupMilliseconds', 'releaseMilliseconds', 'totalMilliseconds'], array_keys($report['timings']));
        self::assertSame(5.0, (float) ($report['cold']['timings']['manifestMilliseconds'] ?? -1));
        self::assertSame(30.0, (float) ($report['cold']['timings']['processMilliseconds'] ?? -1));
        self::assertSame(4.0, (float) ($report['cold']['timings']['runtimeIndexMilliseconds'] ?? -1));
        self::assertSame(6.0, (float) ($report['cold']['timings']['scenariosMilliseconds'] ?? -1));
        self::assertIsArray($report['cold']['runtimeBridgeTimings']);
        self::assertSame('full', $report['cold']['runtimeBridgeTimings']['scope']);
        $runtimeBridgeTotal = $report['cold']['runtimeBridgeTimings']['totalMilliseconds'] ?? null;
        self::assertTrue(\is_int($runtimeBridgeTotal) || \is_float($runtimeBridgeTotal));
        self::assertSame(11.0, (float) $runtimeBridgeTotal);
        $summary = $this->readSummary();
        self::assertTrue($summary['ok']);
        self::assertSame(\PHP_VERSION, $summary['tools']['php']);
        self::assertSame(4, $summary['jobs']);
        self::assertCount(1, $summary['projects']);
        self::assertStringContainsString('cold=ok', $this->lines[0]);
        self::assertStringContainsString('warm=ok', $this->lines[0]);
    }

    public function testFingerprintChangesWhenExpectationsChangeWithoutChangingScenarioIds(): void
    {
        $configuration = $this->configuration();
        $command = $this->command(new FakeProvisioner($this->checkout), new FakeHarness($this->successfulRun(), $this->successfulRun(), $this->successfulRun(), $this->successfulRun()));
        self::assertSame(0, $command->run([$configuration], $this->output));
        $first = $this->readJson($this->output.'/acme/project.json')['expectationFingerprint'];
        $contents = (string) file_get_contents($configuration->scenarioFile);
        $contents = str_replace('"includes":["home"]', '"includes":["changed"]', $contents, $replaced);
        self::assertSame(1, $replaced);
        file_put_contents($configuration->scenarioFile, $contents);

        self::assertSame(0, $command->run([$configuration], $this->output.'/next'));
        self::assertNotSame($first, $this->readJson($this->output.'/next/acme/project.json')['expectationFingerprint']);
    }

    public function testRunsSourceOnlyProjectsWithoutRuntimeMetadata(): void
    {
        $this->diagnosticReport['projects'] = [['complete' => true, 'analysis' => ['mode' => 'source-only'], 'source' => ['state' => 'ready'], 'runtime' => ['state' => 'disabled']]];
        $harness = new FakeHarness($this->successfulSourceOnlyRun(), $this->successfulSourceOnlyRun());

        $exitCode = $this->command(new FakeProvisioner($this->checkout), $harness)->run([$this->configuration(analysisMode: 'source-only')], $this->output);

        self::assertSame(0, $exitCode);
        $report = $this->readReport();
        self::assertTrue($report['ok']);
        $recorded = $this->readJson($this->output.'/acme/project.json');
        self::assertSame('source-only', $recorded['analysisMode'] ?? null);
        self::assertSame([], $report['cold']['layers'] ?? null);
        self::assertSame([], $report['warm']['layers'] ?? null);
        self::assertSame('disabled', $report['warm']['runtime']);
        $diagnostics = $this->readJson($this->output.'/acme/diagnostics.json');
        self::assertTrue($diagnostics['ok'] ?? null);
        self::assertSame('source-only', $diagnostics['analysisMode'] ?? null);
        self::assertStringContainsString('mode=source-only', $this->lines[0]);
    }

    public function testFailsWhenASourceOnlyProjectStillBootsTheRuntime(): void
    {
        $harness = new FakeHarness($this->successfulSourceOnlyRun(), $this->harnessRun(['analysisMode' => 'source-only']));

        $exitCode = $this->command(new FakeProvisioner($this->checkout), $harness)->run([$this->configuration(analysisMode: 'source-only')], $this->output);

        self::assertSame(1, $exitCode);
        $report = $this->readReport();
        self::assertSame([], $report['cold']['layers'] ?? null);
        self::assertSame(['analysis-mode'], $report['warm']['layers'] ?? null);
        self::assertFileDoesNotExist(Path::join($this->output, 'acme/diagnostics.json'));
    }

    public function testFailsWhenARuntimeProjectReportsSourceOnlyRuns(): void
    {
        $harness = new FakeHarness($this->successfulSourceOnlyRun(), $this->successfulSourceOnlyRun());

        $exitCode = $this->command(new FakeProvisioner($this->checkout), $harness)->run([$this->configuration()], $this->output);

        self::assertSame(1, $exitCode);
        $report = $this->readReport();
        self::assertSame(['analysis-mode', 'runtime-index'], $report['cold']['layers'] ?? null);
        self::assertSame(['analysis-mode', 'runtime-index'], $report['warm']['layers'] ?? null);
        self::assertFileDoesNotExist(Path::join($this->output, 'acme/diagnostics.json'));
        self::assertStringContainsString('mode=runtime', $this->lines[0]);
    }

    public function testRejectsInvalidJobCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('job count must be positive');

        $this->command(new FakeProvisioner($this->checkout), new FakeHarness())->run([], $this->output, 0);
    }

    #[DataProvider('jobCountProvider')]
    public function testLimitsConcurrentProjects(int $jobs, int $expectedConcurrency): void
    {
        $harness = new TrackingHarness($this->successfulRun());
        $configurations = [
            $this->configuration(name: 'alpha'),
            $this->configuration(name: 'bravo'),
            $this->configuration(name: 'charlie'),
            $this->configuration(name: 'delta'),
        ];

        $exitCode = $this->command(new FakeProvisioner($this->checkout), $harness)->run($configurations, $this->output, $jobs);

        self::assertSame(0, $exitCode);
        self::assertSame($expectedConcurrency, $harness->maximumConcurrency);
    }

    /** @return iterable<string, array{int, int}> */
    public static function jobCountProvider(): iterable
    {
        yield 'serial' => [1, 1];
        yield 'two workers' => [2, 2];
        yield 'four workers' => [4, 4];
    }

    public function testIgnoresScenarioOrderAndLatencyForCacheParity(): void
    {
        $cold = $this->successfulRun();
        $scenarios = array_reverse($this->scenarioResults());
        $scenarios[0]['checks'][0]['milliseconds'] = 999.0;
        $warm = $this->harnessRun(['scenarios' => $scenarios]);

        self::assertSame(0, $this->command(new FakeProvisioner($this->checkout), new FakeHarness($cold, $warm))->run([$this->configuration()], $this->output));
    }

    public function testFailsWhenNonemptyResponsesChangeBetweenColdAndWarm(): void
    {
        $scenarios = $this->scenarioResults();
        $scenarios[0]['checks'][0]['fingerprint'] = str_repeat('b', 64);
        $warm = $this->harnessRun(['scenarios' => $scenarios]);

        $exitCode = $this->command(new FakeProvisioner($this->checkout), new FakeHarness($this->successfulRun(), $warm))->run([$this->configuration()], $this->output);

        self::assertSame(1, $exitCode);
        self::assertSame('cache-parity', $this->readReport()['failure']['layer'] ?? null);
    }

    public function testFailsWhenTheHarnessOmitsAnExpectedScenario(): void
    {
        $incomplete = $this->harnessRun(['scenarioCount' => 1, 'scenarios' => [$this->scenarioResults()[0]]]);

        self::assertSame(1, $this->command(new FakeProvisioner($this->checkout), new FakeHarness($incomplete, $incomplete))->run([$this->configuration()], $this->output));
        self::assertSame('scenario', $this->readReport()['failure']['layer'] ?? null);
    }

    public function testMissingManifestFailsBeforeProvisioning(): void
    {
        $configuration = $this->configuration();
        unlink($configuration->scenarioFile);
        $provisioner = new FakeProvisioner($this->checkout);
        $harness = new FakeHarness();

        self::assertSame(1, $this->command($provisioner, $harness)->run([$configuration], $this->output));
        self::assertSame([], $harness->applicationRoots);
        self::assertSame([], $provisioner->released);
        self::assertSame('scenario', $this->readReport()['failure']['layer'] ?? null);
    }

    public function testAnAllEmptyServerCannotSatisfyTheEntireMatrixManifest(): void
    {
        $configuration = $this->configuration();
        file_put_contents($configuration->scenarioFile, json_encode([
            'version' => 1, 'revision' => str_repeat('a', 40), 'diagnostics' => [],
            'scenarios' => [['id' => 'negative.only', 'file' => 'templates/page.html.twig', 'anchor' => 'unrelated', 'expect' => ['definition' => ['equals' => []]]]],
        ], \JSON_THROW_ON_ERROR));
        $harness = new FakeHarness();

        self::assertSame(1, $this->command(new FakeProvisioner($this->checkout), $harness)->run([$configuration], $this->output));
        self::assertSame([], $harness->applicationRoots);
        self::assertSame('scenario', $this->readReport()['failure']['layer'] ?? null);
    }

    public function testWholeProjectAnalysisCannotPassWithoutAnalyzingFiles(): void
    {
        $this->diagnosticReport['profile'] = ['projects' => [['files' => 0]]];

        self::assertSame(1, $this->command(new FakeProvisioner($this->checkout), new FakeHarness($this->successfulRun(), $this->successfulRun()))->run([$this->configuration()], $this->output));
        self::assertSame('diagnostics', $this->readReport()['failure']['layer'] ?? null);
    }

    public function testWholeProjectProviderFailureCannotPass(): void
    {
        $this->diagnosticReport['errors'] = [['category' => 'operational']];

        self::assertSame(1, $this->command(new FakeProvisioner($this->checkout), new FakeHarness($this->successfulRun(), $this->successfulRun()))->run([$this->configuration()], $this->output));
        self::assertSame('diagnostics', $this->readReport()['failure']['layer'] ?? null);
    }

    public function testWholeProjectDiagnosticDriftCannotPass(): void
    {
        $this->diagnosticReport['diagnostics'] = [[
            'workspacePath' => 'templates/index.html.twig', 'code' => 'route.not_found', 'severity' => 'error', 'baseline' => 'active', 'message' => 'Unknown route.',
            'range' => ['start' => ['line' => 1, 'character' => 2], 'end' => ['line' => 1, 'character' => 6]],
        ]];

        self::assertSame(1, $this->command(new FakeProvisioner($this->checkout), new FakeHarness($this->successfulRun(), $this->successfulRun()))->run([$this->configuration()], $this->output));
        self::assertSame('diagnostics', $this->readReport()['failure']['layer'] ?? null);
        self::assertFileExists($this->output.'/acme/diagnostics.json');
    }

    public function testUsesTheConfiguredApplicationDirectory(): void
    {
        $application = Path::join($this->checkout, 'app');
        (new Filesystem())->mkdir($application);
        (new Filesystem())->rename(Path::join($this->checkout, 'composer.json'), Path::join($application, 'composer.json'));
        (new Filesystem())->rename(Path::join($this->checkout, 'composer.lock'), Path::join($application, 'composer.lock'));
        $harness = new FakeHarness($this->successfulRun(), $this->successfulRun());

        $exitCode = $this->command(new FakeProvisioner($this->checkout), $harness)->run([$this->configuration(directory: 'app')], $this->output);

        self::assertSame(0, $exitCode);
        self::assertSame([$application, $application], $harness->applicationRoots);
    }

    public function testReportsHarnessFailuresAndStillReleasesTheCheckout(): void
    {
        $provisioner = new FakeProvisioner($this->checkout);
        $failed = $this->harnessRun(['status' => ['source' => ['state' => 'ready'], 'runtime' => ['state' => 'failed']]]);

        $exitCode = $this->command($provisioner, new FakeHarness($this->successfulRun(), $failed))->run([$this->configuration()], $this->output);

        self::assertSame(1, $exitCode);
        self::assertSame(['acme'], $provisioner->released);
        $report = $this->readReport();
        self::assertFalse($report['ok']);
        self::assertSame([], $report['cold']['layers'] ?? null);
        self::assertSame(['runtime-index'], $report['warm']['layers'] ?? null);
        self::assertStringContainsString('warm=runtime-index', $this->lines[0]);
    }

    public function testReleasesTheCheckoutWhenTheHarnessTimesOut(): void
    {
        $provisioner = new FakeProvisioner($this->checkout);
        $timedOut = new HarnessResult(-1, true, null, '', '');

        $exitCode = $this->command($provisioner, new FakeHarness($timedOut, $timedOut))->run([$this->configuration()], $this->output);

        self::assertSame(1, $exitCode);
        self::assertSame(['acme'], $provisioner->released);
        $report = $this->readReport();
        self::assertSame(['timeout'], $report['cold']['layers'] ?? null);
        self::assertSame(['timeout'], $report['warm']['layers'] ?? null);
    }

    public function testArtifactsExposeOnlyTheExpectedKeys(): void
    {
        $this->command(new FakeProvisioner($this->checkout), new FakeHarness($this->successfulRun(), $this->successfulRun()))->run([$this->configuration()], $this->output);

        /** @var array<string, mixed> $report */
        $report = json_decode((string) file_get_contents(Path::join($this->output, 'acme/project.json')), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(
            ['name', 'repository', 'revision', 'directory', 'environment', 'analysisMode', 'setup', 'ci', 'ok', 'failure', 'workingTree', 'dependencies', 'frameworkBundle', 'expectationFingerprint', 'timings', 'cold', 'warm', 'diagnostics', 'knownGaps'],
            array_keys($report),
        );
        /** @var array<string, mixed> $cold */
        $cold = $report['cold'];
        self::assertSame(
            ['layers', 'source', 'runtime', 'scenarios', 'checks', 'requests', 'failures', 'violations', 'maxMilliseconds', 'serverVersion', 'timings', 'runtimeBridgeTimings'],
            array_keys($cold),
        );
        /** @var array<string, mixed> $summary */
        $summary = json_decode((string) file_get_contents(Path::join($this->output, 'summary.json')), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['generatedAt', 'tools', 'jobs', 'projects', 'timings', 'ok'], array_keys($summary));
    }

    public function testReportsProvisioningFailures(): void
    {
        $provisioner = new FakeProvisioner($this->checkout, new ProvisioningException('Revision "b" does not exist.'));
        $harness = new FakeHarness();

        $exitCode = $this->command($provisioner, $harness)->run([$this->configuration()], $this->output);

        self::assertSame(1, $exitCode);
        self::assertSame([], $harness->applicationRoots);
        $report = $this->readReport();
        self::assertSame('provisioning', $report['failure']['layer'] ?? null);
        self::assertSame(['provisionMilliseconds', 'totalMilliseconds'], array_keys($report['timings']));
        self::assertStringContainsString('provisioning', $this->lines[0]);
    }

    public function testReportsSetupFailures(): void
    {
        unlink(Path::join($this->checkout, 'composer.lock'));
        $provisioner = new FakeProvisioner($this->checkout);
        $harness = new FakeHarness();

        $exitCode = $this->command($provisioner, $harness)->run([$this->configuration()], $this->output);

        self::assertSame(1, $exitCode);
        self::assertSame([], $harness->applicationRoots);
        self::assertSame(['acme'], $provisioner->released);
        $report = $this->readReport();
        self::assertSame('setup', $report['failure']['layer'] ?? null);
        self::assertSame(['provisionMilliseconds', 'setupMilliseconds', 'releaseMilliseconds', 'totalMilliseconds'], array_keys($report['timings']));
    }

    public function testRejectsSetupsThatModifyTrackedFiles(): void
    {
        $provisioner = new FakeProvisioner($this->checkout);
        $harness = new FakeHarness();

        $exitCode = $this->command($provisioner, $harness, ' M config/reference.php')->run([$this->configuration()], $this->output);

        self::assertSame(1, $exitCode);
        self::assertSame([], $harness->applicationRoots);
        $report = $this->readReport();
        self::assertSame('setup', $report['failure']['layer'] ?? null);
        self::assertStringContainsString('modified tracked upstream files: config/reference.php', $report['failure']['message']);
    }

    public function testAcceptsDeclaredSetupChanges(): void
    {
        $provisioner = new FakeProvisioner($this->checkout);
        $harness = new FakeHarness($this->successfulRun(), $this->successfulRun());
        $configuration = new ProjectConfiguration('acme', 'https://github.com/acme/app.git', str_repeat('a', 40), null, 'dev', 'composer', false, 120, setupChanges: ['.env.local.demo'], scenarioFile: $this->configuration()->scenarioFile);

        $exitCode = $this->command($provisioner, $harness, ' D .env.local.demo')->run([$configuration], $this->output);

        self::assertSame(0, $exitCode);
        $report = $this->readReport();
        self::assertSame(['modified' => ['.env.local.demo'], 'untracked' => 0], $report['workingTree']);
    }

    private function command(FakeProvisioner $provisioner, HarnessInterface $harness, string $workingTree = '?? vendor/'): MatrixCommand
    {
        $diagnosticReport = json_encode($this->diagnosticReport, \JSON_THROW_ON_ERROR);
        $processes = new FakeProcessRunner(static function (array $command) use ($workingTree, $diagnosticReport): ProcessResult {
            return match (true) {
                'status' === ($command[3] ?? null) => new ProcessResult(0, $workingTree."\n", '', false),
                'check' === ($command[1] ?? null) => new ProcessResult(0, $diagnosticReport, '', false),
                'composer' === $command[0] && 'install' === $command[1] => new ProcessResult(0, '', '', false),
                '--version' === ($command[1] ?? null) => new ProcessResult(0, $command[0].' version 1.0', '', false),
                default => new ProcessResult(1, '', 'Unexpected command '.implode(' ', $command), false),
            };
        });

        return new MatrixCommand(
            $provisioner,
            new SetupRegistry(['composer' => new ComposerSetup($processes)]),
            $harness,
            new RunClassifier(),
            $processes,
            new Filesystem(),
            new RuntimeBridgeTimingNormalizer(),
            function (string $line): void {
                $this->lines[] = $line;
            },
            new DiagnosticCheckHarness($processes, '/server'),
        );
    }

    /** @param 'runtime'|'source-only' $analysisMode */
    private function configuration(?string $directory = null, string $name = 'acme', string $analysisMode = 'runtime'): ProjectConfiguration
    {
        $manifest = $this->directory.'/'.$name.'.scenarios.json';
        file_put_contents($manifest, json_encode([
            'version' => 1, 'revision' => str_repeat('a', 40), 'diagnostics' => [],
            'scenarios' => [
                ['id' => 'route.twig', 'file' => 'templates/index.html.twig', 'anchor' => 'home', 'expect' => ['hover' => ['includes' => ['home']]]],
                ['id' => 'template.php', 'file' => 'src/Controller.php', 'anchor' => 'index.html.twig', 'expect' => ['definition' => ['includes' => ['templates/index.html.twig:0:0-0:0']]]],
            ],
        ], \JSON_THROW_ON_ERROR));

        return new ProjectConfiguration($name, 'https://github.com/acme/app.git', str_repeat('a', 40), $directory, 'dev', 'composer', false, 120, scenarioFile: $manifest, analysisMode: $analysisMode);
    }

    private function successfulSourceOnlyRun(): HarnessResult
    {
        return $this->harnessRun([
            'analysisMode' => 'source-only',
            'status' => ['source' => ['state' => 'ready'], 'runtime' => ['state' => 'disabled'], 'runtimeEnabled' => false],
            'runtimeBridgeTimings' => null,
        ]);
    }

    /** @return list<array{id: string, status: string, checks: list<array{phase: string, method: string, status: string, milliseconds: float, fingerprint: string, failures: list<string>}>, failures: list<string>}> */
    private function scenarioResults(): array
    {
        $scenarios = [];
        foreach (['route.twig' => 'hover', 'template.php' => 'definition'] as $id => $method) {
            $scenarios[] = ['id' => $id, 'status' => 'pass', 'checks' => [[
                'phase' => 'baseline', 'method' => $method, 'status' => 'pass', 'milliseconds' => 12.5, 'fingerprint' => str_repeat('a', 64), 'failures' => [],
            ]], 'failures' => []];
        }

        return $scenarios;
    }

    private function successfulRun(): HarnessResult
    {
        return $this->harnessRun([]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function harnessRun(array $overrides): HarnessResult
    {
        $result = array_merge([
            'status' => ['source' => ['state' => 'ready'], 'runtime' => ['state' => 'ready']],
            'terminal' => true,
            'serverVersion' => '0.15.0',
            'scenarioCount' => 2,
            'requestCount' => 2,
            'assertionFailures' => 0,
            'scenarios' => $this->scenarioResults(),
            'violations' => [],
            'diagnostics' => [],
            'serverError' => null,
            'exitCode' => 0,
            'runtimeBridgeTimings' => [
                'scope' => 'full',
                'bootstrapMilliseconds' => 1.0,
                'kernelMilliseconds' => 2.0,
                'sectionsMilliseconds' => ['routes' => 3.0],
                'shutdownMilliseconds' => 5.0,
                'totalMilliseconds' => 11.0,
            ],
            'timings' => [
                'startupMilliseconds' => 1.0,
                'initializeMilliseconds' => 2.0,
                'sourceIndexMilliseconds' => 3.0,
                'runtimeIndexMilliseconds' => 4.0,
                'indexWaitMilliseconds' => 4.0,
                'manifestMilliseconds' => 5.0,
                'scenariosMilliseconds' => 6.0,
                'shutdownMilliseconds' => 7.0,
                'totalMilliseconds' => 27.0,
            ],
        ], $overrides);

        return new HarnessResult(0, false, $result, json_encode($result, \JSON_THROW_ON_ERROR), '', 8.0, 30.0);
    }

    /**
     * @return array{ok: bool, frameworkBundle: ?string, dependencies: array{composerLockSha256: ?string}, workingTree: array{modified: list<string>, untracked: int}|null, timings: array<string, int|float>, cold: array{layers: list<string>, runtime: string, timings: array<string, int|float|null>, runtimeBridgeTimings: array<string, mixed>|null}|null, warm: array{layers: list<string>, runtime: string, timings: array<string, int|float|null>, runtimeBridgeTimings: array<string, mixed>|null}|null, failure: array{layer: string, message: string}|null}
     */
    private function readReport(): array
    {
        /** @var array{ok: bool, frameworkBundle: ?string, dependencies: array{composerLockSha256: ?string}, workingTree: array{modified: list<string>, untracked: int}|null, timings: array<string, int|float>, cold: array{layers: list<string>, runtime: string, timings: array<string, int|float|null>, runtimeBridgeTimings: array<string, mixed>|null}|null, warm: array{layers: list<string>, runtime: string, timings: array<string, int|float|null>, runtimeBridgeTimings: array<string, mixed>|null}|null, failure: array{layer: string, message: string}|null} $report */
        $report = $this->readJson(Path::join($this->output, 'acme/project.json'));

        return $report;
    }

    /**
     * @return array{ok: bool, tools: array{php: string}, jobs: int, projects: list<mixed>}
     */
    private function readSummary(): array
    {
        /** @var array{ok: bool, tools: array{php: string}, jobs: int, projects: list<mixed>} $summary */
        $summary = $this->readJson(Path::join($this->output, 'summary.json'));

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

final class TrackingHarness implements HarnessInterface
{
    public int $maximumConcurrency = 0;

    private int $concurrency = 0;

    public function __construct(
        private HarnessResult $result,
    ) {
    }

    public function run(ProjectConfiguration $configuration, string $applicationRoot): HarnessResult
    {
        ++$this->concurrency;
        $this->maximumConcurrency = max($this->maximumConcurrency, $this->concurrency);
        try {
            delay(0.01);

            return $this->result;
        } finally {
            --$this->concurrency;
        }
    }
}
