<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\TestWorkspace;
use Symfony\Lsp\Tools\Dogfood\ReportHistory;

final class ReportHistoryTest extends TestCase
{
    public function testRecordsDeterministicIdempotentHistoryThatSurvivesArtifactDeletion(): void
    {
        $workspace = new TestWorkspace('dogfood-history-');
        try {
            $history = new ReportHistory();
            $later = ReportFixture::entry('20260908-120000');
            $earlier = ReportFixture::entry();
            $merged = $history->merge([], [$later, $earlier]);
            self::assertSame(2, $merged['added']);
            self::assertSame([$earlier, $later], $merged['entries']);
            $path = $workspace->path('history/ledger.jsonl');
            $history->save($path, $merged['entries']);
            $contents = file_get_contents($path);
            $again = $history->merge($history->load($path), [$earlier, $later]);
            $history->save($path, $again['entries']);
            self::assertSame(0, $again['added']);
            self::assertSame(0, $again['updated']);
            self::assertSame($contents, file_get_contents($path));
            self::assertSame([$earlier, $later], $history->merge($history->load($path), [])['entries']);
        } finally {
            $workspace->cleanup();
        }
    }

    public function testAnEmptyLedgerCanContainBlankLines(): void
    {
        $workspace = new TestWorkspace('dogfood-history-');
        try {
            $path = $workspace->write('ledger.jsonl', "\n\r\n");
            self::assertSame([], (new ReportHistory())->load($path));
        } finally {
            $workspace->cleanup();
        }
    }

    public function testCompletesAnInterruptedObservationWithoutDuplicatingIt(): void
    {
        $history = new ReportHistory();
        $complete = ReportFixture::entry();
        $partial = array_replace($complete, ['finalized' => false, 'outcome' => 'incomplete', 'layers' => ['artifact'], 'checks' => 1, 'passed' => 1]);
        $merged = $history->merge([$partial], [$complete]);

        self::assertSame(0, $merged['added']);
        self::assertSame(1, $merged['updated']);
        self::assertSame([$complete], $merged['entries']);
        self::assertSame([$complete], $history->merge([$complete], [$partial])['entries']);
    }

    public function testRefusesToRewriteFinalizedResults(): void
    {
        $first = ReportFixture::entry();
        $changed = array_replace($first, ['outcome' => 'failed', 'layers' => ['scenario'], 'passed' => 1, 'failed' => 1]);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('refusing to overwrite finalized history');

        (new ReportHistory())->merge([$first], [$changed]);
    }

    public function testComparisonsRequireRecordedInputsRatherThanCurrentConfiguration(): void
    {
        $history = new ReportHistory();
        self::assertNull($history->comparison(str_repeat('a', 40), str_repeat('b', 64), 'dev', null));
        self::assertNotSame(
            $history->comparison(str_repeat('a', 40), str_repeat('b', 64), 'dev', str_repeat('c', 64)),
            $history->comparison(str_repeat('a', 40), str_repeat('b', 64), 'dev', str_repeat('d', 64)),
        );
    }

    public function testDifferentAnalysisModesAreNeverComparable(): void
    {
        $history = new ReportHistory();
        $runtime = ReportFixture::entry();
        $sourceOnly = array_replace($runtime, ['analysisMode' => 'source-only', 'comparison' => $history->comparison($runtime['revision'], $runtime['dependencies'], $runtime['environment'], $runtime['expectations'], 'source-only')]);
        self::assertNotSame($runtime['comparison'], $sourceOnly['comparison']);
        self::assertNull($history->comparison($runtime['revision'], $runtime['dependencies'], $runtime['environment'], $runtime['expectations'], null));

        $partial = array_replace($runtime, ['outcome' => 'incomplete', 'finalized' => false]);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('input identity');
        $history->merge([$partial], [$sourceOnly]);
    }

    public function testUpgradesLegacyRuntimeHistoryWithoutChangingItsObservations(): void
    {
        $workspace = new TestWorkspace('dogfood-history-');
        try {
            $history = new ReportHistory();
            $expected = ReportFixture::entry();
            $legacy = $expected;
            unset($legacy['analysisMode']);
            $legacy['version'] = 1;
            $legacy['comparison'] = hash('sha256', json_encode([$legacy['revision'], $legacy['dependencies'], $legacy['environment'], $legacy['expectations']], \JSON_THROW_ON_ERROR));
            $path = $workspace->write('ledger.jsonl', json_encode($legacy, \JSON_THROW_ON_ERROR)."\n");
            $loaded = $history->load($path);
            self::assertSame([$expected], $loaded);
            self::assertSame(['entries' => [$expected], 'added' => 0, 'updated' => 0], $history->merge($loaded, [$expected]));
            $history->save($path, $loaded);
            $saved = file_get_contents($path);
            $history->save($path, $history->load($path));
            self::assertSame($saved, file_get_contents($path));
        } finally {
            $workspace->cleanup();
        }
    }

    public function testLegacyUpgradeDoesNotRepairAForgedComparison(): void
    {
        $legacy = ReportFixture::entry();
        unset($legacy['analysisMode']);
        $legacy['version'] = 1;
        $legacy['comparison'] = str_repeat('f', 64);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('comparison fingerprint');
        (new ReportHistory())->validate($legacy);
    }

    public function testObjectKeyOrderingDoesNotRewriteFinalizedHistory(): void
    {
        $entry = ReportFixture::entry();
        self::assertSame(['entries' => [$entry], 'added' => 0, 'updated' => 0], (new ReportHistory())->merge([$entry], [array_reverse($entry, true)]));
    }

    public function testRejectsCorruptLedgersWithoutLosingRecordedData(): void
    {
        $workspace = new TestWorkspace('dogfood-history-');
        try {
            $path = $workspace->write('ledger.jsonl', "{\"version\":1}\n");
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('line 1');
            (new ReportHistory())->load($path);
        } finally {
            self::assertSame("{\"version\":1}\n", file_get_contents($workspace->path('ledger.jsonl')));
            $workspace->cleanup();
        }
    }

    public function testRefusesArbitraryApplicationFieldsInTheLedger(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unsupported history entry shape');

        (new ReportHistory())->validate(ReportFixture::entry() + ['message' => 'credential-canary']);
    }
}
