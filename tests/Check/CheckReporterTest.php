<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Check\BaselineEntry;
use Symfony\Lsp\Check\CheckDiagnostic;
use Symfony\Lsp\Check\CheckDiagnosticOccurrenceNumberer;
use Symfony\Lsp\Check\CheckProjectResult;
use Symfony\Lsp\Check\CheckReporter;
use Symfony\Lsp\Check\CheckReportViewBuilder;
use Symfony\Lsp\Check\CheckResult;
use Symfony\Lsp\Check\DiagnosticCodeRegistry;
use Symfony\Lsp\Check\SarifCheckReporter;

/**
 * @phpstan-type SarifNotification array{descriptor: array{id: string}, properties: array<string, mixed>, exception?: array{kind: string, message: string}, locations?: list<array{physicalLocation: array{artifactLocation: array{uri: string}}}>}
 * @phpstan-type SarifResult array{ruleId: string, ruleIndex: int, level: string, locations: list<array{physicalLocation: array{artifactLocation: array{uri: string}, region: array{startLine: int, startColumn: int, endLine: int, endColumn: int}}}>, suppressions?: list<array{status: string}>, partialFingerprints: array{'symfonyLsp/v1': string}, properties: array<string, mixed>}
 * @phpstan-type SarifRun array{tool: array{driver: array{rules: list<array{id: string}>}}, columnKind: string, invocations: list<array{executionSuccessful: bool, exitCode: int, toolConfigurationNotifications?: list<SarifNotification>, toolExecutionNotifications?: list<SarifNotification>}>, results: list<SarifResult>}
 * @phpstan-type SarifReport array{'$schema': string, version: string, runs: list<SarifRun>}
 */
final class CheckReporterTest extends TestCase
{
    #[DataProvider('reportFormats')]
    public function testReportFormatsMatchGoldenFiles(string $format, int $exitCode): void
    {
        self::assertStringEqualsFile(
            __DIR__.'/Fixtures/report-'.$format.'.'.('json' === $format || 'sarif' === $format ? 'json' : 'txt'),
            $this->reporter()->render($this->goldenResult(), $format, true, $exitCode),
        );
    }

    /** @return iterable<string, array{string, int}> */
    public static function reportFormats(): iterable
    {
        yield 'human' => ['human', 12];
        yield 'json' => ['json', 12];
        yield 'GitHub' => ['github', 12];
        yield 'SARIF' => ['sarif', 12];
    }

    public function testRendersDeterministicJsonCoordinatesAndBaselineState(): void
    {
        $json = $this->reporter()->render($this->fixtureResult(), 'json', false, 0);
        $report = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);

        $coordinates = $report['coordinates'] ?? null;
        $diagnostics = $report['diagnostics'] ?? null;
        $summary = $report['summary'] ?? null;
        self::assertIsArray($coordinates);
        self::assertIsArray($diagnostics);
        self::assertIsArray($diagnostics[0]);
        self::assertIsArray($summary);
        self::assertSame(1, $report['schemaVersion'] ?? null);
        self::assertSame('utf-16', $coordinates['characterEncoding'] ?? null);
        self::assertSame('matched', $diagnostics[0]['baseline'] ?? null);
        self::assertSame([
            'feature' => 'service',
            'provider' => 'dependency-injection',
            'environment' => 'test',
            'analysisMode' => 'source-only',
        ], $diagnostics[0]['provenance'] ?? null);
        self::assertSame(1, $summary['stale'] ?? null);
    }

    public function testRendersSarifRulesLocationsFingerprintsAndBaselines(): void
    {
        /** @var SarifReport $sarif */
        $sarif = json_decode($this->reporter()->render($this->fixtureResult(), 'sarif', false, 10), true, flags: \JSON_THROW_ON_ERROR);
        $run = $sarif['runs'][0];
        $rules = $run['tool']['driver']['rules'];
        $result = $run['results'][0];
        $codes = (new DiagnosticCodeRegistry())->all();
        sort($codes);

        self::assertSame('https://docs.oasis-open.org/sarif/sarif/v2.1.0/errata01/os/schemas/sarif-schema-2.1.0.json', $sarif['$schema']);
        self::assertSame('2.1.0', $sarif['version']);
        self::assertSame($codes, array_column($rules, 'id'));
        self::assertSame('utf16CodeUnits', $run['columnKind']);
        self::assertTrue($run['invocations'][0]['executionSuccessful']);
        self::assertSame(10, $run['invocations'][0]['exitCode']);
        self::assertSame('service.not_found', $result['ruleId']);
        self::assertSame(array_search('service.not_found', $codes, true), $result['ruleIndex']);
        self::assertSame('error', $result['level']);
        self::assertSame('apps/api/config/services.yaml', $result['locations'][0]['physicalLocation']['artifactLocation']['uri']);
        self::assertSame([
            'startLine' => 2,
            'startColumn' => 3,
            'endLine' => 2,
            'endColumn' => 10,
        ], $result['locations'][0]['physicalLocation']['region']);
        self::assertSame('accepted', $result['suppressions'][0]['status'] ?? null);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['partialFingerprints']['symfonyLsp/v1']);
        self::assertSame('dependency-injection', $result['properties']['symfonyLsp.provider'] ?? null);
        self::assertSame('symfony.check.stale_baseline', $run['invocations'][0]['toolConfigurationNotifications'][0]['descriptor']['id'] ?? null);
    }

    public function testRendersSarifOperationalFailuresAndCodeLists(): void
    {
        $result = $this->fixtureResult([[
            'category' => 'operational',
            'message' => 'Template diagnostics failed.',
            'project' => 'apps/api',
            'workspacePath' => 'apps/api/templates/a file.html.twig',
            'provider' => 'template',
            'cause' => ['class' => \UnexpectedValueException::class, 'message' => 'Invalid diagnostic.'],
        ]], false);

        /** @var SarifReport $sarif */
        $sarif = json_decode($this->reporter()->render($result, 'sarif', false, 12), true, flags: \JSON_THROW_ON_ERROR);
        /** @var SarifReport $codes */
        $codes = json_decode($this->reporter()->codes((new DiagnosticCodeRegistry())->all(), 'sarif'), true, flags: \JSON_THROW_ON_ERROR);
        $notification = $sarif['runs'][0]['invocations'][0]['toolExecutionNotifications'][0] ?? null;
        self::assertIsArray($notification);

        self::assertFalse($sarif['runs'][0]['invocations'][0]['executionSuccessful'] ?? true);
        self::assertSame(12, $sarif['runs'][0]['invocations'][0]['exitCode']);
        self::assertSame('template', $notification['properties']['symfonyLsp.provider'] ?? null);
        self::assertSame('UnexpectedValueException', $notification['exception']['kind'] ?? null);
        self::assertSame('apps/api/templates/a%20file.html.twig', $notification['locations'][0]['physicalLocation']['artifactLocation']['uri'] ?? null);
        self::assertSame([], $codes['runs'][0]['results']);
        self::assertSame(0, $codes['runs'][0]['invocations'][0]['exitCode']);
    }

    public function testShowsSanitizedCausesOnlyInJsonAndVerboseHumanReports(): void
    {
        $result = $this->fixtureResult([[
            'category' => 'operational',
            'message' => 'Diagnostic collection failed.',
            'project' => 'apps/api',
            'provider' => 'template',
            'cause' => ['class' => \UnexpectedValueException::class, 'message' => 'Invalid diagnostic.'],
        ]]);

        /** @var array{errors: list<array{provider?: string, cause?: array{message: string}}>} $json */
        $json = json_decode($this->reporter()->render($result, 'json', false, 0), true, flags: \JSON_THROW_ON_ERROR);
        $human = $this->reporter()->render($result, 'human', false, 0);
        $verbose = $this->reporter()->render($result, 'human', true, 0);
        $github = $this->reporter()->render($result, 'github', false, 0);

        self::assertSame('template', $json['errors'][0]['provider'] ?? null);
        self::assertSame('Invalid diagnostic.', $json['errors'][0]['cause']['message'] ?? null);
        self::assertStringNotContainsString('Invalid diagnostic.', $human);
        self::assertStringContainsString('Cause: UnexpectedValueException: Invalid diagnostic.', $verbose);
        self::assertStringNotContainsString('Invalid diagnostic.', $github);
    }

    public function testEscapesGitHubWorkflowCommands(): void
    {
        $output = $this->reporter()->render($this->fixtureResult(), 'github', false, 0);

        self::assertStringContainsString('file=apps/api/config/services.yaml,line=2,col=3,endLine=2,endColumn=9', $output);
        self::assertStringContainsString('title=service.not_found (baseline)', $output);
        self::assertStringContainsString('Missing 100%25%0Aservice', $output);
    }

    public function testKeepsGitHubAnnotationColumnsOrderedForEmptyRanges(): void
    {
        $diagnostic = new CheckDiagnostic(
            'apps/api',
            'config/services.yaml',
            'apps/api/config/services.yaml',
            1,
            5,
            1,
            5,
            1,
            'service.not_found',
            'symfony',
            'Missing service.',
            hash('sha256', 'empty-range'),
        );

        $output = $this->reporter()->render($this->fixtureResult(diagnostic: $diagnostic), 'github', false, 0);

        self::assertStringContainsString('line=2,col=6,endLine=2,endColumn=6', $output);
    }

    private function reporter(): CheckReporter
    {
        return new CheckReporter(
            new SarifCheckReporter(new DiagnosticCodeRegistry(), '1.2.3'),
            new CheckReportViewBuilder(new CheckDiagnosticOccurrenceNumberer()),
        );
    }

    private function goldenResult(): CheckResult
    {
        $fingerprint = hash('sha256', 'duplicate');

        return new CheckResult(
            '1.2.3',
            false,
            [
                new CheckProjectResult(
                    'apps/api',
                    'test',
                    'source-only',
                    'runtime-indexing-disabled',
                    ['state' => 'ready'],
                    ['state' => 'disabled', 'reason' => 'runtime-indexing-disabled'],
                    true,
                ),
                new CheckProjectResult(
                    '.',
                    'prod',
                    'runtime',
                    null,
                    ['state' => 'ready'],
                    ['state' => 'ready', 'stage' => 'container'],
                    false,
                ),
            ],
            [
                new CheckDiagnostic(
                    'apps/api',
                    'config/services.yaml',
                    'apps/api/config/services.yaml',
                    1,
                    2,
                    1,
                    9,
                    1,
                    'service.not_found',
                    'symfony',
                    "Missing 100%\nservice",
                    $fingerprint,
                    'matched',
                    'dependency-injection',
                ),
                new CheckDiagnostic(
                    'apps/api',
                    'config/services.yaml',
                    'apps/api/config/services.yaml',
                    3,
                    4,
                    4,
                    7,
                    2,
                    'service.not_found',
                    'symfony',
                    'Duplicate missing service.',
                    $fingerprint,
                    provider: 'dependency-injection',
                ),
            ],
            [
                new BaselineEntry(
                    '.',
                    'config/removed.yaml',
                    'service.not_found',
                    'error',
                    'symfony',
                    'Removed service.',
                    hash('sha256', 'stale'),
                    1,
                ),
            ],
            '.symfony-lsp-baseline.json',
            'none',
            true,
            [[
                'category' => 'operational',
                'message' => 'Template diagnostics failed.',
                'project' => 'apps/api',
                'environment' => 'test',
                'workspacePath' => 'apps/api/templates/a file.html.twig',
                'provider' => 'template',
                'cause' => ['class' => \UnexpectedValueException::class, 'message' => 'Invalid diagnostic.'],
            ]],
            2,
        );
    }

    /** @param list<array{category: string, message: string, project?: string, provider?: string, cause?: array{class: string, message: string}}> $errors */
    private function fixtureResult(array $errors = [], bool $complete = true, ?CheckDiagnostic $diagnostic = null): CheckResult
    {
        return new CheckResult(
            '1.2.3',
            $complete,
            [new CheckProjectResult(
                'apps/api',
                'test',
                'source-only',
                'runtime-indexing-disabled',
                ['state' => 'ready'],
                ['state' => 'disabled', 'reason' => 'runtime-indexing-disabled'],
                true,
            )],
            [$diagnostic ?? new CheckDiagnostic(
                'apps/api',
                'config/services.yaml',
                'apps/api/config/services.yaml',
                1,
                2,
                1,
                9,
                1,
                'service.not_found',
                'symfony',
                "Missing 100%\nservice",
                hash('sha256', 'diagnostic'),
                'matched',
                'dependency-injection',
            )],
            [new BaselineEntry(
                'apps/api',
                'config/removed.yaml',
                'service.not_found',
                'error',
                'symfony',
                'Removed service.',
                hash('sha256', 'stale'),
                1,
            )],
            '.symfony-lsp-baseline.json',
            'none',
            false,
            $errors,
            0,
        );
    }
}
