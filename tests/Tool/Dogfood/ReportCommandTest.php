<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\ExecutableRunner;
use Symfony\Lsp\Tests\Support\ProcessResult;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class ReportCommandTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('dogfood-report-command-');
        $this->workspace->mkdir('artifacts');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testRecordsHistoryAndHtmlIdempotentlyWithoutChangingExpectations(): void
    {
        $this->fixtures();
        $expectations = $this->workspace->write('scenarios/app.json', '{"independently":"reviewed"}');
        $legacy = $this->workspace->write('support/ledger.jsonl', 'legacy history');
        $first = $this->execute(['--record']);
        self::assertSame(0, $first->exitCode, $first->stderr);
        self::assertStringContainsString('Recorded 1 new observations', $first->stdout);
        $ledger = file_get_contents($this->workspace->path('history/ledger.jsonl'));
        $html = file_get_contents($this->workspace->path('history/index.html'));
        self::assertIsString($html);
        self::assertStringContainsString('dogfood history', $html);

        $second = $this->execute(['--record']);

        self::assertSame(0, $second->exitCode, $second->stderr);
        self::assertStringContainsString('Recorded 0 new observations', $second->stdout);
        self::assertSame($ledger, file_get_contents($this->workspace->path('history/ledger.jsonl')));
        self::assertSame($html, file_get_contents($this->workspace->path('history/index.html')));
        self::assertSame('{"independently":"reviewed"}', file_get_contents($expectations));
        self::assertSame('legacy history', file_get_contents($legacy));
    }

    public function testRegeneratesThePageAfterOriginalArtifactsAreRemoved(): void
    {
        $this->fixtures();
        self::assertSame(0, $this->execute(['--record'])->exitCode);
        $original = file_get_contents($this->workspace->path('history/index.html'));
        foreach (glob($this->workspace->path('artifacts/20260907-120000/app/*.json')) ?: [] as $file) {
            unlink($file);
        }
        unlink($this->workspace->path('history/index.html'));

        $result = $this->execute(['--record']);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame($original, file_get_contents($this->workspace->path('history/index.html')));
    }

    public function testPreviewDoesNotCreateOrRewriteTheDurableLedger(): void
    {
        $this->fixtures();
        $output = $this->workspace->path('preview.html');
        $result = $this->execute(['--output='.$output]);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertFileExists($output);
        self::assertFileDoesNotExist($this->workspace->path('history/ledger.jsonl'));
    }

    public function testRefusesCorruptHistoryWithoutOverwritingItOrThePage(): void
    {
        $this->fixtures();
        $this->workspace->write('history/ledger.jsonl', "not-json\n");
        $this->workspace->write('history/index.html', 'previous page');

        $result = $this->execute(['--record']);

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('Invalid dogfood history entry', $result->stderr);
        self::assertSame("not-json\n", file_get_contents($this->workspace->path('history/ledger.jsonl')));
        self::assertSame('previous page', file_get_contents($this->workspace->path('history/index.html')));
    }

    public function testDoesNotGenerateAnEmptySuccessPageWithoutBehavioralObservations(): void
    {
        $result = $this->execute(['--record']);

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('No behavioral dogfood history', $result->stderr);
        self::assertFileDoesNotExist($this->workspace->path('history/index.html'));
    }

    private function fixtures(): void
    {
        $this->workspace->write('artifacts/20260907-120000/app/project.json', json_encode(ReportFixture::project(), \JSON_THROW_ON_ERROR));
        foreach (['cold', 'warm'] as $phase) {
            $this->workspace->write('artifacts/20260907-120000/app/'.$phase.'.json', json_encode(ReportFixture::phase(), \JSON_THROW_ON_ERROR));
        }
    }

    /** @param list<string> $arguments */
    private function execute(array $arguments): ProcessResult
    {
        return (new ExecutableRunner())->run([
            \PHP_BINARY,
            \dirname(__DIR__, 3).'/tools/dogfood-report',
            '--matrix-dir='.$this->workspace->path('artifacts'),
            '--history-dir='.$this->workspace->path('history'),
            ...$arguments,
        ]);
    }
}
