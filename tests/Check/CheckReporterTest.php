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
        self::assertSame(1, $summary['stale'] ?? null);
    }

    public function testEscapesGitHubWorkflowCommands(): void
    {
        $output = (new CheckReporter())->render($this->fixtureResult(), 'github');

        self::assertStringContainsString('file=apps/api/config/services.yaml,line=2,col=3,endLine=2,endColumn=9', $output);
        self::assertStringContainsString('title=service.not_found%3Aprimary (baseline)', $output);
        self::assertStringContainsString('Missing 100%25%0Aservice', $output);
    }

    private function fixtureResult(): CheckResult
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
            [],
            0,
        );
    }
}
