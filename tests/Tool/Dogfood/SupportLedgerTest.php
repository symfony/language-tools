<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\SupportHtmlReport;
use Symfony\Lsp\Tools\Dogfood\SupportLedger;

final class SupportLedgerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/lsp-support-'.bin2hex(random_bytes(4)).'/ledger.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(\dirname($this->path));
    }

    public function testRecordsOnlyNewRunAndProjectPairsSorted(): void
    {
        $ledger = new SupportLedger($this->path);
        $first = ['run' => '20260822-1200', 'project' => 'kimai', 'score' => 0.73, 'probeCount' => 15, 'fingerprint' => 'abc'];

        self::assertSame(1, $ledger->record([$first]));
        self::assertSame(0, $ledger->record([['run' => '20260822-1200', 'project' => 'kimai', 'score' => 0.99]]));
        self::assertSame(2, $ledger->record([
            ['run' => '20260822-1400', 'project' => 'kimai', 'score' => 0.78],
            ['run' => '20260822-1100', 'project' => 'mautic', 'score' => 0.57],
        ]));

        $entries = $ledger->entries();
        self::assertSame(
            [['20260822-1100', 'mautic'], ['20260822-1200', 'kimai'], ['20260822-1400', 'kimai']],
            array_map(static fn (array $entry): array => [$entry['run'], $entry['project']], $entries),
        );
        self::assertSame(0.73, $entries[1]['score']);
    }

    public function testHtmlReportEmbedsTheLedger(): void
    {
        $html = (new SupportHtmlReport())->render([
            ['run' => '20260822-1200', 'project' => 'kimai', 'score' => 0.73, 'probeCount' => 15, 'fingerprint' => 'abc', 'categories' => ['route.php' => 0.75]],
        ]);

        self::assertStringContainsString('"project":"kimai"', $html);
        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('dogfood-support --record', $html);
        self::assertStringNotContainsString('__DATA__', $html);
    }
}
