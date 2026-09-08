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
