<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\CoverageAggregator;
use Symfony\Lsp\Tools\Dogfood\CoverageException;

final class CoverageAggregatorTest extends TestCase
{
    private const IDENTITY = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER_IDENTITY = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

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
        self::assertSame(self::IDENTITY, $report->sourceIdentity);
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

    public function testRejectsArtifactsMeasuredOnDifferentSourceTrees(): void
    {
        $this->expectException(CoverageException::class);
        $this->expectExceptionMessage('Coverage artifacts "cold.json" and "warm.json" measured different source trees');

        (new CoverageAggregator())->aggregate([
            'cold.json' => self::artifact(['src/Server/Server.php' => ['executed' => [10], 'unexecuted' => []]]),
            'warm.json' => self::artifact(['src/Server/Server.php' => ['executed' => [10], 'unexecuted' => []]], self::OTHER_IDENTITY),
        ]);
    }

    public function testRejectsArtifactsThatNoLongerMatchTheCurrentSourceTree(): void
    {
        $this->expectException(CoverageException::class);
        $this->expectExceptionMessage(\sprintf('Coverage artifact "cold.json" measured source %s, but the current source tree is %s', self::IDENTITY, self::OTHER_IDENTITY));

        (new CoverageAggregator())->aggregate(
            ['cold.json' => self::artifact(['src/Server/Server.php' => ['executed' => [10], 'unexecuted' => []]])],
            ['src/Server/Server.php'],
            self::OTHER_IDENTITY,
        );
    }

    public function testAcceptsArtifactsMatchingTheCurrentSourceTree(): void
    {
        $report = (new CoverageAggregator())->aggregate(
            ['cold.json' => self::artifact(['src/Server/Server.php' => ['executed' => [10], 'unexecuted' => []]])],
            ['src/Server/Server.php', 'src/Index/Index.php'],
            self::IDENTITY,
        );

        self::assertSame(self::IDENTITY, $report->sourceIdentity);
        self::assertSame(['src/Index/Index.php'], $report->uncoveredFiles());
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
        yield 'scalar document' => ['12', 'Coverage artifact "broken.json" is not in the "symfony-lsp-coverage/2" format'];
        yield 'unknown format' => ['{"format":"other/1","files":{}}', 'is not in the "symfony-lsp-coverage/2" format'];
        yield 'superseded format' => ['{"format":"symfony-lsp-coverage/1","files":{}}', 'is not in the "symfony-lsp-coverage/2" format'];
        yield 'missing source identity' => ['{"format":"symfony-lsp-coverage/2","files":{}}', 'does not carry a source identity'];
        yield 'truncated source identity' => ['{"format":"symfony-lsp-coverage/2","source":"sha256:abcdef","files":{}}', 'does not carry a source identity'];
        yield 'unhashed source identity' => ['{"format":"symfony-lsp-coverage/2","source":"main","files":{}}', 'does not carry a source identity'];
        yield 'missing files map' => [self::document(null), 'does not contain a "files" map'];
        yield 'invalid file entry' => [self::document('{"src/A.php":3}'), 'contains invalid data for "src/A.php"'];
        yield 'missing lines' => [self::document('{"src/A.php":{}}'), 'does not list "executed" lines for "src/A.php"'];
        yield 'line map instead of list' => [
            self::document('{"src/A.php":{"executed":{"4":true},"unexecuted":[]}}'),
            'does not list "executed" lines for "src/A.php"',
        ];
        yield 'line zero' => [
            self::document('{"src/A.php":{"executed":[0],"unexecuted":[]}}'),
            'contains an invalid "executed" line number for "src/A.php"',
        ];
        yield 'line as string' => [
            self::document('{"src/A.php":{"executed":["4"],"unexecuted":[]}}'),
            'contains an invalid "executed" line number for "src/A.php"',
        ];
        yield 'vendor file' => [
            self::document('{"vendor/a/b.php":{"executed":[4],"unexecuted":[]}}'),
            'The path "vendor/a/b.php" from coverage artifact "broken.json" is not a PHP file below "src/"',
        ];
        yield 'absolute file' => [
            self::document('{"/tmp/app/src/A.php":{"executed":[4],"unexecuted":[]}}'),
            'is not a PHP file below "src/"',
        ];
        yield 'escaping file' => [
            self::document('{"src/../tools/A.php":{"executed":[4],"unexecuted":[]}}'),
            'is not a PHP file below "src/"',
        ];
        yield 'twig template' => [
            self::document('{"src/A.twig":{"executed":[4],"unexecuted":[]}}'),
            'is not a PHP file below "src/"',
        ];
        yield 'branch map instead of list' => [
            self::document('{"src/A.php":{"executed":[],"unexecuted":[],"branches":{"a":1}}}'),
            'does not list branches for "src/A.php"',
        ];
        yield 'branch without hit flag' => [
            self::document('{"src/A.php":{"executed":[],"unexecuted":[],"branches":[{"function":"f","op":0,"line":4}]}}'),
            'contains an invalid branch entry for "src/A.php"',
        ];
        yield 'branch without line' => [
            self::document('{"src/A.php":{"executed":[],"unexecuted":[],"branches":[{"function":"f","op":0,"hit":true}]}}'),
            'contains an invalid branch entry for "src/A.php"',
        ];
    }

    public function testRejectsSourceFilesOutsideTheMeasuredTree(): void
    {
        $this->expectException(CoverageException::class);
        $this->expectExceptionMessage('The path "tools/dogfood-server" from the source file list is not a PHP file below "src/"');

        (new CoverageAggregator())->aggregate([], ['tools/dogfood-server']);
    }

    public function testAggregatesTheStampedFormatWrittenByTheBootstrap(): void
    {
        $bootstrap = (string) file_get_contents(\dirname(__DIR__, 3).'/tools/dogfood/coverage-bootstrap.php');

        self::assertStringContainsString("'format' => '".CoverageAggregator::FORMAT."'", $bootstrap);
        self::assertStringContainsString("'source' => \$identity", $bootstrap);
        self::assertStringContainsString('SourceIdentity::of($root.\'/src\')', $bootstrap);
    }

    private static function document(?string $files): string
    {
        return \sprintf('{"format":"%s","source":"%s"%s}', CoverageAggregator::FORMAT, self::IDENTITY, null === $files ? '' : ',"files":'.$files);
    }

    /**
     * @param array<string, array{executed: list<int>, unexecuted: list<int>, branches?: list<array<string, mixed>>}> $files
     */
    private static function artifact(array $files, string $identity = self::IDENTITY): string
    {
        return json_encode(['format' => CoverageAggregator::FORMAT, 'source' => $identity, 'files' => $files], \JSON_THROW_ON_ERROR);
    }
}
