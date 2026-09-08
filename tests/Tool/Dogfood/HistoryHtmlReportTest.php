<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\HistoryHtmlReport;

final class HistoryHtmlReportTest extends TestCase
{
    public function testShowsAnEmptyStateWhenNothingWasRecorded(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([]));

        self::assertSame('No dogfood observations recorded yet.', self::text($document, '.empty'));
        self::assertCount(0, $document->querySelectorAll('table'));
        self::assertCount(0, $document->querySelectorAll('#project-filter'));
    }

    public function testHistoryStripsKeepDistinctOutcomeColors(): void
    {
        $entries = [];
        foreach (['passed', 'failed', 'blocked', 'incomplete'] as $index => $outcome) {
            $entries[] = self::entry(['run' => \sprintf('2026010%d-000000', $index + 1), 'outcome' => $outcome]);
        }
        $document = self::parse((new HistoryHtmlReport())->render($entries));

        self::assertSame([
            'background:var(--passed)', 'background:var(--failed)', 'background:var(--blocked)', 'background:var(--incomplete)',
        ], array_map(static fn (Element $chip): string => $chip->getAttribute('style') ?? '', self::elements($document, '.strip .chip')));
    }

    public function testZeroCountChartsHaveUnambiguousAxisLabels(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([self::entry(['knownGaps' => 0, 'diagnostics' => 0])]));
        $ticks = self::elements(self::figure($document, 'Known gaps and diagnostics'), 'text[text-anchor="end"]');

        self::assertSame(['1', '0'], array_map(static fn (Element $tick): string => $tick->textContent ?? '', $ticks));
    }

    public function testShowsOutcomeChangesEvenWhenCheckCountsAreIdentical(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([
            self::entry(['outcome' => 'failed', 'layers' => ['cache-parity'], 'passed' => 240, 'failed' => 0]),
            self::entry(['run' => '20260102-000000', 'passed' => 240, 'failed' => 0]),
        ]));

        self::assertStringContainsString('Failed → Passed', self::text($document, '[data-project-panel] .wrap'));
    }

    public function testDoesNotComparePartialCheckCountsWithACompletedRun(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([
            self::entry(),
            self::entry(['run' => '20260102-000000', 'outcome' => 'incomplete', 'finalized' => false, 'passed' => 1]),
        ]));
        $change = self::text($document, '[data-project-panel] .wrap');

        self::assertStringContainsString('run incomplete', $change);
        self::assertStringNotContainsString('passed -', $change);
    }

    public function testCountAxesDoNotRoundTheirMidpointToTheWrongValue(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([self::entry(['knownGaps' => 1, 'diagnostics' => 3])]));
        $ticks = self::elements(self::figure($document, 'Known gaps and diagnostics'), 'text[text-anchor="end"]');

        self::assertSame(['4', '2', '0'], array_map(static fn (Element $tick): string => $tick->textContent ?? '', $ticks));
    }

    public function testTinyDurationsKeepTheirFractionalAxisLabels(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([self::entry(['milliseconds' => 1.0, 'scenarioMilliseconds' => 0.5])]));
        $ticks = self::elements(self::figure($document, 'Duration'), 'text[text-anchor="end"]');

        self::assertSame(['1 ms', '0.5 ms', '0 ms'], array_map(static fn (Element $tick): string => $tick->textContent ?? '', $ticks));
    }

    public function testKeepsHostileValuesAsTextInsteadOfMarkup(): void
    {
        $hostile = '<script>alert("x")</script>';
        $html = (new HistoryHtmlReport())->render([
            self::entry([
                'project' => $hostile,
                'environment' => '</style><img src=x onerror=alert(1)>',
                'revision' => '" onmouseover="alert(1)',
                'layers' => ['<b>process</b>'],
            ]),
            self::entry(['project' => 'a b']),
            self::entry(['project' => 'a-b']),
        ]);
        $document = self::parse($html);

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertCount(0, $document->querySelectorAll('img'));
        self::assertCount(0, $document->querySelectorAll('*[onerror]'));
        self::assertCount(0, $document->querySelectorAll('*[onmouseover]'));
        self::assertCount(1, $document->querySelectorAll('script'));

        $panels = self::elements($document, '[data-project-panel]');
        self::assertSame([$hostile, 'a b', 'a-b'], array_map(static fn (Element $panel): string => self::text($panel, 'h2'), $panels));
        self::assertCount(3, array_unique(array_map(static fn (Element $panel): string => $panel->getAttribute('data-project-panel') ?? '', $panels)));
        self::assertSame('<b>process</b>', self::text($panels[0], '.layers'));
    }

    public function testKeepsUndecodableTextOutOfThePage(): void
    {
        $html = (new HistoryHtmlReport())->render([self::entry(['project' => "kimai\xB1"])]);

        self::assertStringNotContainsString("\xB1", $html);
        self::assertSame('kimai'."\u{FFFD}", self::text(self::parse($html), '[data-project-panel] h2'));
    }

    public function testBlockedRunWithoutMeasurementsNeverReadsAsASuccess(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([
            self::entry(),
            self::unmeasured([
                'run' => '20260102-000000',
                'outcome' => 'blocked',
                'finalized' => false,
                'layers' => ['process'],
            ]),
        ]));

        self::assertSame(
            ['kimai', '2', '20260102-000000', 'Blocked Runtime process not finalized', '', 'unknown', 'unknown', 'unknown', 'unknown', 'unknown', 'unknown'],
            self::rows(self::element($document, 'table'))[1],
        );
        self::assertSame('badge outcome-blocked', self::element($document, 'tbody .badge')->getAttribute('class'));
        self::assertSame(
            ['20260102-000000', 'unknown', 'Blocked Runtime process not finalized', 'unknown', 'unknown', 'unknown', 'unknown', 'unknown', 'unknown', 'unknown', 'unknown', 'unknown', 'unknown', 'Passed → Blocked; not comparable, run incomplete'],
            self::rows(self::elements($document, 'table')[1])[1],
        );
    }

    public function testUnmeasuredValuesAreNotPlottedAsZero(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([
            self::entry(['run' => '20260101-000000', 'files' => 4210]),
            self::entry(['run' => '20260102-000000', 'files' => null]),
            self::entry(['run' => '20260103-000000', 'files' => 4300]),
        ]));

        $files = self::figure($document, 'Files analyzed');
        self::assertCount(2, $files->querySelectorAll('circle'));
        self::assertCount(0, $files->querySelectorAll('polyline'));

        $checks = self::figure($document, 'Checks');
        self::assertSame(3, self::pointCount(self::element($checks, 'polyline.s1')));
    }

    public function testShowsChartsWithoutAnyMeasurementAsEmpty(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([self::unmeasured()]));

        self::assertSame('No measurement recorded for this chart.', self::text(self::figure($document, 'Duration'), '.unknown'));
        self::assertCount(0, $document->querySelectorAll('figure svg'));
    }

    public function testComparesOnlyObservationsSharingAComparisonFingerprint(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([
            self::entry(['run' => '20260101-000000', 'checks' => 104, 'passed' => 100, 'failed' => 4]),
            self::entry(['run' => '20260102-000000', 'checks' => 104, 'passed' => 104, 'failed' => 0]),
            self::entry(['run' => '20260103-000000', 'checks' => 44, 'passed' => 40, 'comparison' => str_repeat('9', 64)]),
            self::entry(['run' => '20260104-000000', 'checks' => 64, 'passed' => 60, 'comparison' => null]),
        ]));

        $changes = array_map(static fn (array $row): string => $row[13], self::rows(self::elements($document, 'table')[1]));
        self::assertSame([
            'Compared with previous',
            'not comparable, comparison fingerprint unknown',
            'not comparable, revision, dependencies, environment or expectations changed',
            'passed +4, failed -4',
            'first observation',
        ], $changes);

        $checks = self::figure($document, 'Checks');
        self::assertCount(2, $checks->querySelectorAll('.break'));
        self::assertSame(
            ['20260103-000000 cannot be compared with the previous observation', '20260104-000000 cannot be compared with the previous observation'],
            array_map(static fn (Element $line): string => self::text($line, 'title'), self::elements($checks, '.break')),
        );
        self::assertSame([2, 2, 2], array_map(self::pointCount(...), self::elements($checks, 'polyline')));
    }

    public function testLabelsSourceOnlyResultsWithoutComparingThemWithRuntimeResults(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([
            self::entry(),
            self::entry(['run' => '20260102-000000', 'analysisMode' => 'source-only', 'passed' => 100]),
        ]));

        self::assertSame('Source-only', self::text($document, 'table .analysis-mode'));
        self::assertStringContainsString('Source-only observations do not boot the application', (string) self::element($document, 'body')->textContent);
        self::assertSame('not comparable, analysis mode changed or unknown', self::text($document, '[data-project-panel] .wrap'));
        self::assertCount(0, self::figure($document, 'Checks')->querySelectorAll('polyline'));
        self::assertCount(1, self::figure($document, 'Checks')->querySelectorAll('.break'));
    }

    public function testSeparatesKnownGapsFromFailures(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([self::entry(['knownGaps' => 7, 'failed' => 0])]));

        $history = self::rows(self::elements($document, 'table')[1]);
        self::assertSame(['Failed', 'Known gaps'], [$history[0][6], $history[0][8]]);
        self::assertSame(['0', '7'], [$history[1][6], $history[1][8]]);
        self::assertStringContainsString('Known gaps are confirmed analyzer limitations retained explicitly until fixed', self::text(self::figure($document, 'Known gaps and diagnostics'), '.note'));
    }

    public function testKeepsEveryMeasurementInsideItsOwnProject(): void
    {
        $document = self::parse((new HistoryHtmlReport())->render([
            self::entry(['project' => 'sylius']),
            self::entry(['project' => 'kimai']),
        ]));

        self::assertCount(2, $document->querySelectorAll('[data-project-panel]'));
        self::assertCount(3, $document->querySelectorAll('#project-filter option'));
        self::assertCount(10, $document->querySelectorAll('figure'));
        self::assertCount(10, $document->querySelectorAll('[data-project-panel] figure'));
        self::assertStringNotContainsString('%', (string) self::element($document, 'body')->textContent);
        self::assertSame('2 observations, 2 projects, 1 run, 20260101-000000 to 20260101-000000.', self::text($document, '.summary'));
    }

    public function testRendersTheSameBytesForTheSameObservations(): void
    {
        $entries = [self::entry(['run' => '20200102-030405', 'time' => '2020-01-02T03:04:05+00:00'])];

        $html = (new HistoryHtmlReport())->render($entries);

        self::assertSame($html, (new HistoryHtmlReport())->render($entries));
        self::assertStringNotContainsString(date('Y-m-d'), $html);
    }

    public function testEmbedsEveryAssetInThePage(): void
    {
        $html = (new HistoryHtmlReport())->render([self::entry()]);

        foreach (['http', '<link', 'url(', '@import', '<iframe', 'fetch('] as $external) {
            self::assertStringNotContainsString($external, $html);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function entry(array $overrides = []): array
    {
        return array_merge([
            'version' => 2,
            'analysisMode' => 'runtime',
            'run' => '20260101-000000',
            'project' => 'kimai',
            'time' => '2026-01-01T00:00:00+00:00',
            'outcome' => 'passed',
            'finalized' => true,
            'layers' => [],
            'revision' => str_repeat('a', 40),
            'dependencies' => str_repeat('b', 64),
            'environment' => 'php-8.4',
            'framework' => '6.4.9',
            'serverVersion' => '0.18.0',
            'expectations' => str_repeat('c', 64),
            'checkSet' => str_repeat('d', 64),
            'comparison' => str_repeat('e', 64),
            'scenarios' => 12,
            'checks' => 240,
            'passed' => 236,
            'failed' => 4,
            'errors' => 0,
            'requests' => 130,
            'knownGaps' => 7,
            'diagnostics' => 19,
            'files' => 4210,
            'milliseconds' => 41234.5,
            'scenarioMilliseconds' => 12034.25,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function unmeasured(array $overrides = []): array
    {
        return self::entry(array_merge([
            'outcome' => 'incomplete',
            'time' => null,
            'comparison' => null,
            'scenarios' => null,
            'checks' => null,
            'passed' => null,
            'failed' => null,
            'errors' => null,
            'requests' => null,
            'knownGaps' => null,
            'diagnostics' => null,
            'files' => null,
            'milliseconds' => null,
            'scenarioMilliseconds' => null,
        ], $overrides));
    }

    private static function parse(string $html): HTMLDocument
    {
        self::assertStringStartsWith('<!DOCTYPE html>', $html);

        return HTMLDocument::createFromString($html);
    }

    private static function figure(HTMLDocument $document, string $caption): Element
    {
        foreach (self::elements($document, 'figure') as $figure) {
            if ($caption === self::text($figure, 'figcaption')) {
                return $figure;
            }
        }

        self::fail(\sprintf('No chart is captioned "%s".', $caption));
    }

    private static function element(HTMLDocument|Element $scope, string $selector): Element
    {
        $element = $scope->querySelector($selector);
        if (!$element instanceof Element) {
            self::fail(\sprintf('No element matches "%s".', $selector));
        }

        return $element;
    }

    /**
     * @return list<Element>
     */
    private static function elements(HTMLDocument|Element $scope, string $selector): array
    {
        $elements = [];
        foreach ($scope->querySelectorAll($selector) as $element) {
            $elements[] = $element;
        }

        return $elements;
    }

    private static function text(HTMLDocument|Element $scope, string $selector): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) self::element($scope, $selector)->textContent));
    }

    /**
     * @return list<list<string>>
     */
    private static function rows(Element $table): array
    {
        $rows = [];
        foreach (self::elements($table, 'tr') as $row) {
            $cells = [];
            foreach (self::elements($row, 'th,td') as $cell) {
                $cells[] = trim((string) preg_replace('/\s+/u', ' ', (string) $cell->textContent));
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    private static function pointCount(Element $polyline): int
    {
        return \count(explode(' ', trim($polyline->getAttribute('points') ?? '')));
    }
}
