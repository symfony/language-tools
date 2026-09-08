<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\TestWorkspace;
use Symfony\Lsp\Tools\Dogfood\ReportHistory;
use Symfony\Lsp\Tools\Dogfood\ReportImporter;

final class ReportImporterTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('dogfood-report-import-');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testImportsVerifiedChecksAndRecordedComparisonInputs(): void
    {
        ReportFixture::write($this->workspace);
        $result = (new ReportImporter())->collect($this->workspace->path());
        self::assertSame([], $result['warnings']);
        self::assertCount(1, $result['entries']);
        $entry = $result['entries'][0];
        self::assertSame('passed', $entry['outcome']);
        self::assertSame('2026-09-07T12:00:00Z', $entry['time']);
        self::assertSame(2, $entry['checks']);
        self::assertSame(2, $entry['passed']);
        self::assertSame(0, $entry['failed']);
        self::assertSame(0, $entry['knownGaps']);
        self::assertSame(123, $entry['files']);
        self::assertSame(24.0, $entry['scenarioMilliseconds']);
        self::assertSame(ReportFixture::entry()['comparison'], $entry['comparison']);
    }

    public function testDecorativeVersionLabelsCannotDiscardVerifiedMeasurements(): void
    {
        $project = ReportFixture::project();
        $project['frameworkBundle'] = 'dev-feature/example';
        $project['warm'] = array_replace((array) $project['warm'], ['serverVersion' => 'unrecognized version label']);
        ReportFixture::write($this->workspace, project: $project);
        $entry = (new ReportImporter())->collect($this->workspace->path())['entries'][0];

        self::assertSame('passed', $entry['outcome']);
        self::assertSame(2, $entry['passed']);
        self::assertSame('dev-feature/example', $entry['framework']);
        self::assertNull($entry['serverVersion']);
    }

    public function testArtifactDirectoriesAreLiteralPathsRatherThanGlobPatterns(): void
    {
        ReportFixture::write($this->workspace, 'collection[one]/20260907-120000');
        $result = (new ReportImporter())->collect($this->workspace->path('collection[one]'));

        self::assertCount(1, $result['entries']);
        self::assertSame('passed', $result['entries'][0]['outcome']);
        self::assertSame([], $result['warnings']);
    }

    public function testRetainsSetupFailuresWithoutInventingZeroMeasurementsOrLeakingOutput(): void
    {
        $project = array_replace(ReportFixture::project(), [
            'ok' => false, 'failure' => ['layer' => 'setup', 'message' => 'token=credential-canary'],
            'cold' => null, 'warm' => null, 'diagnostics' => null,
            'dependencies' => ['composerLockSha256' => null],
        ]);
        $this->workspace->write('20260907-120000/app/project.json', json_encode($project, \JSON_THROW_ON_ERROR));
        $result = (new ReportImporter())->collect($this->workspace->path());
        $entry = $result['entries'][0];
        self::assertSame('blocked', $entry['outcome']);
        self::assertSame(['setup'], $entry['layers']);
        self::assertTrue($entry['finalized']);
        self::assertNull($entry['checks']);
        self::assertNull($entry['knownGaps']);
        self::assertNull($entry['files']);
        self::assertNull($entry['comparison']);
        self::assertStringNotContainsString('credential-canary', json_encode($result, \JSON_THROW_ON_ERROR));
    }

    public function testTreatsInvalidManifestsAsBlockedRatherThanFailedAssertions(): void
    {
        $project = array_replace(ReportFixture::project(), [
            'ok' => false, 'failure' => ['layer' => 'scenario'],
            'cold' => null, 'warm' => null, 'diagnostics' => null,
        ]);
        $this->workspace->write('20260907-120000/app/project.json', json_encode($project, \JSON_THROW_ON_ERROR));
        $entry = (new ReportImporter())->collect($this->workspace->path())['entries'][0];

        self::assertSame('blocked', $entry['outcome']);
        self::assertNull($entry['checks']);
        self::assertSame(['scenario'], $entry['layers']);
    }

    public function testDistinguishesFailedAssertionsFromOperationalErrors(): void
    {
        $project = ReportFixture::project();
        $project['ok'] = false;
        $project['cold'] = array_replace((array) $project['cold'], ['layers' => ['scenario'], 'failures' => 1]);
        $project['warm'] = array_replace((array) $project['warm'], ['layers' => ['scenario'], 'failures' => 1]);
        ReportFixture::write($this->workspace, project: $project, status: 'fail');
        $entry = (new ReportImporter())->collect($this->workspace->path())['entries'][0];

        self::assertSame('failed', $entry['outcome']);
        self::assertSame(0, $entry['passed']);
        self::assertSame(2, $entry['failed']);
        self::assertSame(0, $entry['errors']);
    }

    public function testDoesNotGuessMissingExpectationFingerprintsFromCurrentFiles(): void
    {
        $project = ReportFixture::project();
        unset($project['expectationFingerprint']);
        ReportFixture::write($this->workspace, project: $project);
        $entry = (new ReportImporter())->collect($this->workspace->path())['entries'][0];

        self::assertSame('passed', $entry['outcome']);
        self::assertNull($entry['expectations']);
        self::assertNull($entry['comparison']);
        self::assertIsString($entry['checkSet']);
    }

    public function testPreservesAnInterruptedRunWithoutASummaryOrProjectReport(): void
    {
        $this->workspace->write('20260907-120000/app/cold.json', json_encode(ReportFixture::phase(), \JSON_THROW_ON_ERROR));
        $entry = (new ReportImporter())->collect($this->workspace->path())['entries'][0];

        self::assertSame('incomplete', $entry['outcome']);
        self::assertFalse($entry['finalized']);
        self::assertSame(1, $entry['passed']);
        self::assertNull($entry['files']);
        self::assertNull($entry['comparison']);
    }

    public function testLegacyProbeScoresAreNotBehavioralEvidence(): void
    {
        $this->workspace->write('20260801-120000/app/project.json', json_encode([
            'name' => 'app', 'ok' => true, 'cold' => ['probes' => 5, 'supportScore' => 1.0], 'warm' => ['probes' => 5, 'supportScore' => 1.0],
        ], \JSON_THROW_ON_ERROR));
        $result = (new ReportImporter())->collect($this->workspace->path());

        self::assertSame([], $result['entries']);
        self::assertSame(1, $result['legacy']);
    }

    public function testCorruptArtifactsAreReportedWithoutEchoingTheirContents(): void
    {
        ReportFixture::write($this->workspace);
        $this->workspace->write('20260907-120000/app/cold.json', '{"token":"credential-canary"');
        $result = (new ReportImporter())->collect($this->workspace->path());

        self::assertSame('incomplete', $result['entries'][0]['outcome']);
        self::assertSame([
            '20260907-120000/app: Artifact is not valid JSON.',
            '20260907-120000/app: Passing result lacks complete evidence.',
        ], $result['warnings']);
        self::assertStringNotContainsString('credential-canary', json_encode($result, \JSON_THROW_ON_ERROR));
    }

    public function testCountsOnlyKnownGapsActuallyObservedInTheAnalysis(): void
    {
        $project = ReportFixture::project();
        $project['ok'] = false;
        $project['failure'] = ['layer' => 'diagnostics'];
        $project['knownGaps'] = [['path' => 'src/Controller.php', 'code' => 'form.unknown_option', 'severity' => 'error', 'range' => [
            'start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 4],
        ], 'messageHash' => str_repeat('e', 64), 'reason' => 'private-canary']];
        ReportFixture::write($this->workspace, project: $project);
        $entry = (new ReportImporter())->collect($this->workspace->path())['entries'][0];

        self::assertSame('failed', $entry['outcome']);
        self::assertSame(0, $entry['knownGaps']);
        self::assertStringNotContainsString('private-canary', json_encode($entry, \JSON_THROW_ON_ERROR));
    }

    public function testKeepsARecordedProcessFailureWhenRawPhaseFilesAreNotJson(): void
    {
        $project = ReportFixture::project();
        $project['ok'] = false;
        $project['cold'] = array_replace((array) $project['cold'], ['layers' => ['process']]);
        $project['warm'] = array_replace((array) $project['warm'], ['layers' => ['process']]);
        $project['diagnostics'] = null;
        ReportFixture::write($this->workspace, project: $project);
        $this->workspace->write('20260907-120000/app/cold.json', 'Process crashed: token=credential-canary');
        $this->workspace->write('20260907-120000/app/warm.json', 'Process crashed: token=credential-canary');
        $result = (new ReportImporter())->collect($this->workspace->path());

        self::assertSame([], $result['warnings']);
        self::assertSame('blocked', $result['entries'][0]['outcome']);
        self::assertNull($result['entries'][0]['checks']);
        self::assertStringNotContainsString('credential-canary', json_encode($result, \JSON_THROW_ON_ERROR));
    }

    public function testDamagedPhaseArtifactsStayRepairableRatherThanFinalizingABlockedRun(): void
    {
        $project = ReportFixture::project();
        $project['ok'] = false;
        $project['cold'] = array_replace((array) $project['cold'], ['layers' => ['scenario'], 'failures' => 1]);
        $project['warm'] = array_replace((array) $project['warm'], ['layers' => ['scenario'], 'failures' => 1]);
        ReportFixture::write($this->workspace, project: $project, status: 'fail');
        foreach (['cold', 'warm'] as $phase) {
            $this->workspace->write('20260907-120000/app/'.$phase.'.json', 'damaged artifact');
        }
        $importer = new ReportImporter();
        $damaged = $importer->collect($this->workspace->path());
        self::assertSame('incomplete', $damaged['entries'][0]['outcome']);
        self::assertFalse($damaged['entries'][0]['finalized']);
        ReportFixture::write($this->workspace, project: $project, status: 'fail');
        $repaired = $importer->collect($this->workspace->path());
        $merged = (new ReportHistory())->merge($damaged['entries'], $repaired['entries']);

        self::assertSame(1, $merged['updated']);
        self::assertSame('failed', $merged['entries'][0]['outcome']);
        self::assertSame(2, $merged['entries'][0]['failed']);
        self::assertTrue($merged['entries'][0]['finalized']);
    }

    public function testRefusesAGreenResultWithMissingEvidence(): void
    {
        ReportFixture::write($this->workspace);
        unlink($this->workspace->path('20260907-120000/app/warm.json'));
        $result = (new ReportImporter())->collect($this->workspace->path());

        self::assertSame('incomplete', $result['entries'][0]['outcome']);
        self::assertFalse($result['entries'][0]['finalized']);
        self::assertSame(['20260907-120000/app: Passing result lacks complete evidence.'], $result['warnings']);
    }
}
