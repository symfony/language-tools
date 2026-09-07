<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\CoverageAggregator;
use Symfony\Lsp\Tools\Dogfood\CoverageException;

final class CoverageAggregatorTest extends TestCase
{
    public function testUnionsDuplicateProcessDataIndependentlyOfOrder(): void
    {
        $cold = self::artifact([
            'src/Server/Server.php' => ['executed' => [10, 12], 'unexecuted' => [14, 16]],
            'src/Index/Index.php' => ['executed' => [3], 'unexecuted' => [5]],
        ]);
        $warm = self::artifact([
            'src/Server/Server.php' => ['executed' => [10, 14], 'unexecuted' => [16]],
        ]);

        $report = (new CoverageAggregator())->aggregate(['cold.json' => $cold, 'warm.json' => $warm]);

        self::assertSame(2, $report->artifactCount);
        self::assertSame([10, 12, 14], $report->files['src/Server/Server.php']->executedLines);
        self::assertSame([16], $report->files['src/Server/Server.php']->unexecutedLines);
        self::assertSame(4, $report->executedLineCount());
        self::assertSame(6, $report->executableLineCount());
        self::assertSame(2, $report->executedFileCount());
        self::assertSame(
            (new CoverageAggregator())->aggregate(['warm.json' => $warm, 'cold.json' => $cold])->toArray(),
            $report->toArray(),
        );
    }

    public function testOrdersFilesAndLinesDeterministically(): void
    {
        $report = (new CoverageAggregator())->aggregate(['run.json' => self::artifact([
            'src/Server/Server.php' => ['executed' => [12, 4], 'unexecuted' => [30, 8]],
            'src/Index/Index.php' => ['executed' => [7], 'unexecuted' => []],
        ])]);

        self::assertSame(['src/Index/Index.php', 'src/Server/Server.php'], array_keys($report->files));
        self::assertSame([4, 12], $report->files['src/Server/Server.php']->executedLines);
        self::assertSame([8, 30], $report->files['src/Server/Server.php']->unexecutedLines);
    }

    public function testReportsFilesTheDriverSawWithoutExecutingThem(): void
    {
        $report = (new CoverageAggregator())->aggregate(['run.json' => self::artifact([
            'src/Feature/Loaded.php' => ['executed' => [], 'unexecuted' => [11, 12]],
            'src/Feature/Used.php' => ['executed' => [4], 'unexecuted' => []],
        ])]);

        self::assertSame(['src/Feature/Loaded.php'], $report->uncoveredFiles());
        self::assertFalse($report->files['src/Feature/Loaded.php']->isExecuted());
        self::assertSame(2, $report->files['src/Feature/Loaded.php']->executableLineCount());
        self::assertSame(1, $report->executedFileCount());
    }

    public function testCountsSourceFilesNeverLoadedByAnyProcess(): void
    {
        $report = (new CoverageAggregator())->aggregate(
            ['run.json' => self::artifact(['src/Feature/Used.php' => ['executed' => [4], 'unexecuted' => []]])],
            ['src/Feature/Used.php', 'src/Feature/Never.php'],
        );

        self::assertSame(['src/Feature/Never.php', 'src/Feature/Used.php'], array_keys($report->files));
        self::assertSame(['src/Feature/Never.php'], $report->uncoveredFiles());
        self::assertSame([], $report->files['src/Feature/Never.php']->executedLines);
        self::assertSame(0, $report->files['src/Feature/Never.php']->executableLineCount());
    }

    public function testUnionsBranchHitsAcrossProcesses(): void
    {
        $branches = static fn (bool $first, bool $second): array => [
            ['function' => 'Server::run', 'op' => 0, 'line' => 12, 'hit' => $first],
            ['function' => 'Server::run', 'op' => 1, 'line' => 14, 'hit' => $second],
        ];
        $report = (new CoverageAggregator())->aggregate([
            'cold.json' => self::artifact(['src/Server/Server.php' => ['executed' => [12], 'unexecuted' => [], 'branches' => $branches(true, false)]]),
            'warm.json' => self::artifact(['src/Server/Server.php' => ['executed' => [12], 'unexecuted' => [], 'branches' => $branches(false, false)]]),
        ]);

        self::assertSame(2, $report->branchCount());
        self::assertSame(1, $report->hitBranchCount());
        self::assertSame([14], $report->files['src/Server/Server.php']->unhitBranchLines);
    }

    public function testKeepsBranchlessArtifactsUsable(): void
    {
        $report = (new CoverageAggregator())->aggregate(['run.json' => self::artifact([
            'src/Server/Server.php' => ['executed' => [12], 'unexecuted' => []],
        ])]);

        self::assertSame(0, $report->branchCount());
        self::assertSame(0, $report->hitBranchCount());
        self::assertSame([], $report->files['src/Server/Server.php']->unhitBranchLines);
    }

    #[DataProvider('malformedArtifactProvider')]
    public function testRejectsMalformedArtifacts(string $document, string $expectedMessage): void
    {
        $this->expectException(CoverageException::class);
        $this->expectExceptionMessage($expectedMessage);

        (new CoverageAggregator())->aggregate(['broken.json' => $document]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedArtifactProvider(): iterable
    {
        yield 'truncated JSON' => ['{"format":', 'Coverage artifact "broken.json" is not valid JSON'];
        yield 'scalar document' => ['12', 'Coverage artifact "broken.json" is not in the "symfony-lsp-coverage/1" format'];
        yield 'unknown format' => ['{"format":"other/1","files":{}}', 'is not in the "symfony-lsp-coverage/1" format'];
        yield 'missing files map' => ['{"format":"symfony-lsp-coverage/1"}', 'does not contain a "files" map'];
        yield 'invalid file entry' => ['{"format":"symfony-lsp-coverage/1","files":{"src/A.php":3}}', 'contains invalid data for "src/A.php"'];
        yield 'missing lines' => ['{"format":"symfony-lsp-coverage/1","files":{"src/A.php":{}}}', 'does not list "executed" lines for "src/A.php"'];
        yield 'line map instead of list' => [
            '{"format":"symfony-lsp-coverage/1","files":{"src/A.php":{"executed":{"4":true},"unexecuted":[]}}}',
            'does not list "executed" lines for "src/A.php"',
        ];
        yield 'line zero' => [
            '{"format":"symfony-lsp-coverage/1","files":{"src/A.php":{"executed":[0],"unexecuted":[]}}}',
            'contains an invalid "executed" line number for "src/A.php"',
        ];
        yield 'line as string' => [
            '{"format":"symfony-lsp-coverage/1","files":{"src/A.php":{"executed":["4"],"unexecuted":[]}}}',
            'contains an invalid "executed" line number for "src/A.php"',
        ];
        yield 'vendor file' => [
            '{"format":"symfony-lsp-coverage/1","files":{"vendor/a/b.php":{"executed":[4],"unexecuted":[]}}}',
            'The path "vendor/a/b.php" from coverage artifact "broken.json" is not a PHP file below "src/"',
        ];
        yield 'absolute file' => [
            '{"format":"symfony-lsp-coverage/1","files":{"/tmp/app/src/A.php":{"executed":[4],"unexecuted":[]}}}',
            'is not a PHP file below "src/"',
        ];
        yield 'escaping file' => [
            '{"format":"symfony-lsp-coverage/1","files":{"src/../tools/A.php":{"executed":[4],"unexecuted":[]}}}',
            'is not a PHP file below "src/"',
        ];
        yield 'twig template' => [
            '{"format":"symfony-lsp-coverage/1","files":{"src/A.twig":{"executed":[4],"unexecuted":[]}}}',
            'is not a PHP file below "src/"',
        ];
        yield 'branch map instead of list' => [
            '{"format":"symfony-lsp-coverage/1","files":{"src/A.php":{"executed":[],"unexecuted":[],"branches":{"a":1}}}}',
            'does not list branches for "src/A.php"',
        ];
        yield 'branch without hit flag' => [
            '{"format":"symfony-lsp-coverage/1","files":{"src/A.php":{"executed":[],"unexecuted":[],"branches":[{"function":"f","op":0,"line":4}]}}}',
            'contains an invalid branch entry for "src/A.php"',
        ];
        yield 'branch without line' => [
            '{"format":"symfony-lsp-coverage/1","files":{"src/A.php":{"executed":[],"unexecuted":[],"branches":[{"function":"f","op":0,"hit":true}]}}}',
            'contains an invalid branch entry for "src/A.php"',
        ];
    }

    public function testRejectsSourceFilesOutsideTheMeasuredTree(): void
    {
        $this->expectException(CoverageException::class);
        $this->expectExceptionMessage('The path "tools/dogfood-server" from the source file list is not a PHP file below "src/"');

        (new CoverageAggregator())->aggregate([], ['tools/dogfood-server']);
    }

    public function testAggregatesTheFormatWrittenByTheBootstrap(): void
    {
        $bootstrap = (string) file_get_contents(\dirname(__DIR__, 3).'/tools/dogfood/coverage-bootstrap.php');

        self::assertStringContainsString("'format' => '".CoverageAggregator::FORMAT."'", $bootstrap);
    }

    /**
     * @param array<string, array{executed: list<int>, unexecuted: list<int>, branches?: list<array<string, mixed>>}> $files
     */
    private static function artifact(array $files): string
    {
        return json_encode(['format' => CoverageAggregator::FORMAT, 'files' => $files], \JSON_THROW_ON_ERROR);
    }
}
