<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\ExecutableRunner;
use Symfony\Lsp\Tests\Support\ProcessResult;
use Symfony\Lsp\Tests\Support\TestWorkspace;
use Symfony\Lsp\Tools\Dogfood\CoverageAggregator;

final class CoverageExecutableTest extends TestCase
{
    private const COVERED_FILE = 'src/Server/ServerVersion.php';
    private const UNCOVERED_FILE = 'src/Server/MemoryLimit.php';

    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-coverage-');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testDocumentsTheMatrixUsageInHelp(): void
    {
        $result = $this->execute(['tools/dogfood-coverage', '--help']);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringContainsString('tools/dogfood-matrix --server ./tools/dogfood-coverage', $result->stdout);
        self::assertStringContainsString('tools/dogfood-coverage-report', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testReportsStartupProblemsInsteadOfStartingAnUninstrumentedServer(): void
    {
        $result = $this->execute(['tools/dogfood-coverage', '--server='.$this->workspace->path('missing-server')]);

        self::assertSame(1, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertStringContainsString(\sprintf('The language server "%s" does not exist.', $this->workspace->path('missing-server')), $result->stderr);
    }

    public function testFailsWhenTheCoverageDriverIsMissing(): void
    {
        if (\extension_loaded('xdebug')) {
            self::markTestSkipped('Xdebug is loaded.');
        }

        $result = $this->execute(['tools/dogfood-coverage']);

        self::assertSame(1, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertStringContainsString('Xdebug is not loaded', $result->stderr);
    }

    public function testTheBootstrapStopsTheProcessWhenNoDriverIsAvailable(): void
    {
        if (\extension_loaded('xdebug')) {
            self::markTestSkipped('Xdebug is loaded.');
        }

        $result = $this->runBootstrap($this->workspace->write('server.php', "<?php\necho 'server started';\n"));

        self::assertSame(3, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertStringContainsString('no coverage driver is available', $result->stderr);
        self::assertSame([], glob($this->workspace->path('artifacts').'/*'));
    }

    public function testTheBootstrapStopsTheProcessWhenTheTreeSitterExtensionIsMissing(): void
    {
        $result = $this->runBootstrap($this->workspace->write('server.php', "<?php\necho 'server started';\n"), treeSitter: false);

        self::assertSame(3, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertStringContainsString('the Tree-sitter extension is not loaded', $result->stderr);
        self::assertSame([], glob($this->workspace->path('artifacts').'/*'));
    }

    public function testTheBootstrapWritesOneSourceOnlyArtifactPerProcess(): void
    {
        if (!\extension_loaded('xdebug')) {
            self::markTestSkipped('Xdebug is not loaded.');
        }
        if (!is_file($this->extension())) {
            self::markTestSkipped('The Tree-sitter extension is not built.');
        }

        $result = $this->runBootstrap($this->workspace->write('server.php', \sprintf(
            "<?php\nrequire '%s/vendor/autoload.php';\n(new \\Symfony\\Lsp\\Server\\MemoryLimit())->isValid('512M');\n",
            $this->root(),
        )));

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame('', $result->stdout);
        $artifacts = glob($this->workspace->path('artifacts').'/coverage-*.json') ?: [];
        self::assertCount(1, $artifacts);
        $artifact = json_decode((string) file_get_contents($artifacts[0]), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($artifact);
        self::assertSame(CoverageAggregator::FORMAT, $artifact['format'] ?? null);
        $measured = $artifact['files'] ?? null;
        self::assertIsArray($measured);
        foreach (array_keys($measured) as $path) {
            self::assertStringStartsWith('src/', (string) $path);
        }
        $report = (new CoverageAggregator())->aggregate(['artifact.json' => (string) file_get_contents($artifacts[0])]);
        self::assertTrue($report->files[self::UNCOVERED_FILE]->isExecuted());
    }

    public function testTheReportUnionsArtifactsAsJson(): void
    {
        $this->writeArtifacts();

        $result = $this->execute(['tools/dogfood-coverage-report', '--format=json', $this->workspace->path('artifacts')]);

        self::assertSame(0, $result->exitCode, $result->stderr);
        $report = json_decode($result->stdout, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(2, $report['artifacts'] ?? null);
        $files = $report['files'] ?? null;
        self::assertIsArray($files);
        $covered = $files[self::COVERED_FILE] ?? null;
        self::assertIsArray($covered);
        self::assertSame([4, 8, 12], $covered['executedLines'] ?? null);
        self::assertSame([20], $covered['unexecutedLines'] ?? null);
        self::assertSame(2, $covered['branches'] ?? null);
        self::assertSame(1, $covered['hitBranches'] ?? null);
        $totals = $report['totals'] ?? null;
        self::assertIsArray($totals);
        self::assertSame(1, $totals['executedFiles'] ?? null);
        self::assertGreaterThan(100, $totals['files'] ?? null);
        $uncovered = $report['uncoveredFiles'] ?? null;
        self::assertIsArray($uncovered);
        self::assertContains(self::UNCOVERED_FILE, $uncovered);
        self::assertNotContains(self::COVERED_FILE, $uncovered);
    }

    public function testTheReportSeparatesReachFromCorrectness(): void
    {
        $this->writeArtifacts();

        $result = $this->execute(['tools/dogfood-coverage-report', '--limit=1', $this->workspace->path('artifacts')]);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringContainsString('Artifacts: 2', $result->stdout);
        self::assertStringContainsString('Files:     1/', $result->stdout);
        self::assertStringContainsString('Lines:     3/4 executed (75.0%)', $result->stdout);
        self::assertStringContainsString('Branches:  1/2 taken (50.0%)', $result->stdout);
        self::assertStringContainsString('Execution is reach, not correctness', $result->stdout);
        self::assertStringContainsString('more, use --limit=0', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testTheReportRejectsMalformedArtifacts(): void
    {
        $this->workspace->write('artifacts/coverage-1-aaaa.json', '{"format":');

        $result = $this->execute(['tools/dogfood-coverage-report', $this->workspace->path('artifacts')]);

        self::assertSame(1, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertStringContainsString('is not valid JSON', $result->stderr);
    }

    public function testTheReportFailsWithoutArtifacts(): void
    {
        $this->workspace->mkdir('artifacts');

        $result = $this->execute(['tools/dogfood-coverage-report', $this->workspace->path('artifacts')]);

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('No coverage artifact in', $result->stderr);
    }

    private function writeArtifacts(): void
    {
        $this->workspace->write('artifacts/coverage-1-aaaa.json', $this->artifact([4, 8], [12, 20], true));
        $this->workspace->write('artifacts/coverage-2-bbbb.json', $this->artifact([12], [20], false));
    }

    /**
     * @param list<int> $executed
     * @param list<int> $unexecuted
     */
    private function artifact(array $executed, array $unexecuted, bool $hit): string
    {
        return json_encode([
            'format' => CoverageAggregator::FORMAT,
            'files' => [self::COVERED_FILE => [
                'executed' => $executed,
                'unexecuted' => $unexecuted,
                'branches' => [
                    ['function' => 'ServerVersion::value', 'op' => 0, 'line' => 8, 'hit' => $hit],
                    ['function' => 'ServerVersion::value', 'op' => 1, 'line' => 20, 'hit' => false],
                ],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }

    private function runBootstrap(string $script, bool $treeSitter = true): ProcessResult
    {
        $this->workspace->mkdir('artifacts');
        $command = [\PHP_BINARY];
        if ($treeSitter && is_file($this->extension())) {
            $command = [...$command, '-d', 'extension='.$this->extension()];
        }

        return (new ExecutableRunner())->run(
            [...$command, '-d', 'auto_prepend_file='.$this->root().'/tools/dogfood/coverage-bootstrap.php', '-d', 'xdebug.mode=coverage', $script],
            $this->root(),
            [...getenv(), 'XDEBUG_MODE' => 'coverage', 'SYMFONY_LSP_COVERAGE_DIR' => $this->workspace->path('artifacts')],
        );
    }

    /**
     * @param list<string> $arguments
     */
    private function execute(array $arguments): ProcessResult
    {
        return (new ExecutableRunner())->run([\PHP_BINARY, ...$arguments], $this->root());
    }

    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function extension(): string
    {
        return $this->root().'/var/build/tree_sitter/modules/symfony_lsp_tree_sitter.so';
    }
}
