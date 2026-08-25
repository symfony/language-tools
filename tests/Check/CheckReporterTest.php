<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Check\BaselineEntry;
use Symfony\Lsp\Check\CheckDiagnostic;
use Symfony\Lsp\Check\CheckProjectResult;
use Symfony\Lsp\Check\CheckReporter;
use Symfony\Lsp\Check\CheckResult;

final class CheckReporterTest extends TestCase
{
    public function testRendersDeterministicJsonCoordinatesAndBaselineState(): void
    {
        $json = (new CheckReporter())->render($this->fixtureResult(), 'json');
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
        $json = json_decode((new CheckReporter())->render($result, 'json'), true, flags: \JSON_THROW_ON_ERROR);
        $human = (new CheckReporter())->render($result, 'human');
        $verbose = (new CheckReporter())->render($result, 'human', true);
        $github = (new CheckReporter())->render($result, 'github');

        self::assertSame('template', $json['errors'][0]['provider'] ?? null);
        self::assertSame('Invalid diagnostic.', $json['errors'][0]['cause']['message'] ?? null);
        self::assertStringNotContainsString('Invalid diagnostic.', $human);
        self::assertStringContainsString('Cause: UnexpectedValueException: Invalid diagnostic.', $verbose);
        self::assertStringNotContainsString('Invalid diagnostic.', $github);
    }

    public function testEscapesGitHubWorkflowCommands(): void
    {
        $output = (new CheckReporter())->render($this->fixtureResult(), 'github');

        self::assertStringContainsString('file=apps/api/config/services.yaml,line=2,col=3,endLine=2,endColumn=9', $output);
        self::assertStringContainsString('title=service.not_found%3Aprimary (baseline)', $output);
        self::assertStringContainsString('Missing 100%25%0Aservice', $output);
    }

    /** @param list<array{category: string, message: string, project?: string, provider?: string, cause?: array{class: string, message: string}}> $errors */
    private function fixtureResult(array $errors = []): CheckResult
    {
        return new CheckResult(
            '1.2.3',
            true,
            [new CheckProjectResult(
                'apps/api',
                'test',
                'source-only',
                'runtime-indexing-disabled',
                ['state' => 'ready'],
                ['state' => 'disabled', 'reason' => 'runtime-indexing-disabled'],
                true,
            )],
            [new CheckDiagnostic(
                'apps/api',
                'config/services.yaml',
                'apps/api/config/services.yaml',
                1,
                2,
                1,
                9,
                1,
                'service.not_found:primary',
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
