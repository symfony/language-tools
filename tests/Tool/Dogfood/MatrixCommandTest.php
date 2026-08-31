<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\ComposerSetup;
use Symfony\Lsp\Tools\Dogfood\HarnessResult;
use Symfony\Lsp\Tools\Dogfood\MatrixCommand;
use Symfony\Lsp\Tools\Dogfood\ProcessResult;
use Symfony\Lsp\Tools\Dogfood\ProjectConfiguration;
use Symfony\Lsp\Tools\Dogfood\ProvisioningException;
use Symfony\Lsp\Tools\Dogfood\RunClassifier;
use Symfony\Lsp\Tools\Dogfood\SetupRegistry;

final class MatrixCommandTest extends TestCase
{
    private string $directory;
    private string $checkout;
    private string $output;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-dogfood-'.bin2hex(random_bytes(8)));
        $this->checkout = Path::join($this->directory, 'checkout');
        $this->output = Path::join($this->directory, 'output');
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
        self::assertSame('8.1.0', $report['frameworkBundle']);
        self::assertNotEmpty($report['dependencies']['composerLockSha256']);
        self::assertSame(['modified' => [], 'untracked' => 1], $report['workingTree']);
        self::assertSame([], $report['cold']['layers'] ?? null);
        self::assertSame([], $report['warm']['layers'] ?? null);
        self::assertSame(['provisionMilliseconds', 'setupMilliseconds', 'releaseMilliseconds', 'totalMilliseconds'], array_keys($report['timings']));
        self::assertSame(8.0, (float) ($report['cold']['timings']['budgetProbeDiscoveryMilliseconds'] ?? -1));
        self::assertSame(30.0, (float) ($report['cold']['timings']['processMilliseconds'] ?? -1));
        self::assertSame(4.0, (float) ($report['cold']['timings']['runtimeIndexMilliseconds'] ?? -1));
        $summary = $this->readSummary();
        self::assertTrue($summary['ok']);
        self::assertSame(\PHP_VERSION, $summary['tools']['php']);
        self::assertCount(1, $summary['projects']);
        self::assertStringContainsString('cold=ok', $this->lines[0]);
        self::assertStringContainsString('warm=ok', $this->lines[0]);
    }

    public function testIgnoresDiagnosticAndFieldOrderingForCacheParity(): void
    {
        $first = [
            'severity' => 1,
            'code' => 'route.not_found',
            'range' => ['start' => ['line' => 1, 'character' => 2], 'end' => ['line' => 1, 'character' => 4]],
            'message' => 'Missing route.',
        ];
        $second = [
            'code' => 'template.not_found',
            'message' => 'Missing template.',
            'severity' => 1,
        ];
        $cold = $this->harnessRun(['diagnostics' => [[
            'uri' => 'file:///workspace/config/services.yaml',
            'items' => [$first, $second],
        ]]]);
        $warm = $this->harnessRun(['diagnostics' => [[
            'items' => [array_reverse($second, true), array_reverse($first, true)],
            'uri' => 'file:///workspace/config/services.yaml',
        ]]]);

        $exitCode = $this->command(new FakeProvisioner($this->checkout), new FakeHarness($cold, $warm))->run([$this->configuration()], $this->output);

        self::assertSame(0, $exitCode);
    }

    public function testFailsWhenColdAndWarmDiagnosticsDiffer(): void
    {
        $cold = $this->harnessRun(['diagnostics' => [[
            'uri' => 'file:///workspace/config/services.yaml',
            'items' => [['code' => 'service.not_found']],
        ]]]);
        $warm = $this->harnessRun(['diagnostics' => [[
            'uri' => 'file:///workspace/config/services.yaml',
            'items' => [],
        ]]]);

        $exitCode = $this->command(new FakeProvisioner($this->checkout), new FakeHarness($cold, $warm))->run([$this->configuration()], $this->output);

        self::assertSame(1, $exitCode);
        $report = $this->readReport();
        self::assertSame('cache-parity', $report['failure']['layer'] ?? null);
        self::assertStringContainsString('Cold and warm diagnostic publications differ.', $report['failure']['message']);
    }

    /**
     * @param list<mixed> $coldDiagnostics
     * @param list<mixed> $warmDiagnostics
     */
    #[DataProvider('malformedDiagnosticsProvider')]
    public function testMalformedDiagnosticsRemainSignificantForCacheParity(array $coldDiagnostics, array $warmDiagnostics): void
    {
        $cold = $this->harnessRun(['diagnostics' => $coldDiagnostics]);
        $warm = $this->harnessRun(['diagnostics' => $warmDiagnostics]);

        $exitCode = $this->command(new FakeProvisioner($this->checkout), new FakeHarness($cold, $warm))->run([$this->configuration()], $this->output);

        self::assertSame(1, $exitCode);
        self::assertSame('cache-parity', $this->readReport()['failure']['layer'] ?? null);
    }

    /** @return iterable<string, array{list<mixed>, list<mixed>}> */
    public static function malformedDiagnosticsProvider(): iterable
    {
        yield 'publication' => [[42], []];
        yield 'item' => [[[
            'uri' => 'file:///workspace/config/services.yaml',
            'items' => [42],
        ]], [[
            'uri' => 'file:///workspace/config/services.yaml',
            'items' => [],
        ]]];
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
            ['name', 'repository', 'revision', 'directory', 'environment', 'setup', 'ci', 'ok', 'failure', 'workingTree', 'dependencies', 'frameworkBundle', 'timings', 'cold', 'warm'],
            array_keys($report),
        );
        /** @var array<string, mixed> $cold */
        $cold = $report['cold'];
        self::assertSame(
            ['layers', 'source', 'runtime', 'probes', 'requestErrors', 'violations', 'maxMilliseconds', 'serverVersion', 'supportScore', 'timings'],
            array_keys($cold),
        );
        /** @var array<string, mixed> $summary */
        $summary = json_decode((string) file_get_contents(Path::join($this->output, 'summary.json')), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['generatedAt', 'tools', 'projects', 'timings', 'ok'], array_keys($summary));
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
        $configuration = new ProjectConfiguration('acme', 'https://github.com/acme/app.git', str_repeat('a', 40), null, 'dev', 'composer', false, 120, setupChanges: ['.env.local.demo']);

        $exitCode = $this->command($provisioner, $harness, ' D .env.local.demo')->run([$configuration], $this->output);

        self::assertSame(0, $exitCode);
        $report = $this->readReport();
        self::assertSame(['modified' => ['.env.local.demo'], 'untracked' => 0], $report['workingTree']);
    }

    private function command(FakeProvisioner $provisioner, FakeHarness $harness, string $workingTree = '?? vendor/'): MatrixCommand
    {
        $processes = new FakeProcessRunner(static function (array $command) use ($workingTree): ProcessResult {
            return match (true) {
                'status' === ($command[3] ?? null) => new ProcessResult(0, $workingTree."\n", '', false),
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
            function (string $line): void {
                $this->lines[] = $line;
            },
        );
    }

    private function configuration(?string $directory = null): ProjectConfiguration
    {
        return new ProjectConfiguration('acme', 'https://github.com/acme/app.git', str_repeat('a', 40), $directory, 'dev', 'composer', false, 120);
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
            'probeCount' => 2,
            'probes' => [
                ['requests' => ['hover' => ['milliseconds' => 12.5, 'resultCount' => 1, 'error' => null]]],
                ['requests' => ['definition' => ['milliseconds' => 3.1, 'resultCount' => 1, 'error' => null]]],
            ],
            'violations' => [],
            'diagnostics' => [],
            'serverError' => null,
            'exitCode' => 0,
            'timings' => [
                'startupMilliseconds' => 1.0,
                'initializeMilliseconds' => 2.0,
                'sourceIndexMilliseconds' => 3.0,
                'runtimeIndexMilliseconds' => 4.0,
                'indexWaitMilliseconds' => 4.0,
                'probeDiscoveryMilliseconds' => 5.0,
                'requestsMilliseconds' => 6.0,
                'shutdownMilliseconds' => 7.0,
                'totalMilliseconds' => 27.0,
            ],
        ], $overrides);

        return new HarnessResult(0, false, $result, json_encode($result, \JSON_THROW_ON_ERROR), '', 8.0, 30.0);
    }

    /**
     * @return array{ok: bool, frameworkBundle: ?string, dependencies: array{composerLockSha256: ?string}, workingTree: array{modified: list<string>, untracked: int}|null, timings: array<string, int|float>, cold: array{layers: list<string>, timings: array<string, int|float|null>}|null, warm: array{layers: list<string>, timings: array<string, int|float|null>}|null, failure: array{layer: string, message: string}|null}
     */
    private function readReport(): array
    {
        /** @var array{ok: bool, frameworkBundle: ?string, dependencies: array{composerLockSha256: ?string}, workingTree: array{modified: list<string>, untracked: int}|null, timings: array<string, int|float>, cold: array{layers: list<string>, timings: array<string, int|float|null>}|null, warm: array{layers: list<string>, timings: array<string, int|float|null>}|null, failure: array{layer: string, message: string}|null} $report */
        $report = $this->readJson(Path::join($this->output, 'acme/project.json'));

        return $report;
    }

    /**
     * @return array{ok: bool, tools: array{php: string}, projects: list<mixed>}
     */
    private function readSummary(): array
    {
        /** @var array{ok: bool, tools: array{php: string}, projects: list<mixed>} $summary */
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
