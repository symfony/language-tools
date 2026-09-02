<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Check\BaselineCodec;
use Symfony\Lsp\Check\BaselineManager;
use Symfony\Lsp\Check\BaselineMatcher;
use Symfony\Lsp\Check\BaselineRepository;
use Symfony\Lsp\Check\CheckDiagnostic;
use Symfony\Lsp\Check\CheckDiagnosticOccurrenceNumberer;
use Symfony\Lsp\Check\CheckFile;
use Symfony\Lsp\Check\CheckOptions;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\Project;

final class BaselineManagerTest extends TestCase
{
    private string $directory;
    private BaselineManager $manager;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/symfony-lsp-baseline-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->manager = $this->manager(new Filesystem());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testGeneratedBaselineMatchesGoldenFile(): void
    {
        $diagnostic = $this->diagnostic('same-fingerprint');
        $this->manager->apply($this->directory, $this->options('create'), [$diagnostic, $diagnostic]);

        self::assertFileEquals(__DIR__.'/Fixtures/baseline-v1.json', $this->directory.'/baseline.json');
    }

    public function testMatchesDuplicateOccurrencesByMultiplicityAndReportsStaleEntries(): void
    {
        $diagnostic = $this->diagnostic('same-fingerprint');
        $create = $this->options('create');
        $created = $this->manager->apply($this->directory, $create, [$diagnostic]);
        self::assertSame('matched', $created['diagnostics'][0]->baselineState);

        $matched = $this->manager->apply($this->directory, $this->options(), [$diagnostic, $diagnostic]);
        self::assertSame(['matched', 'active'], array_map(
            static fn (CheckDiagnostic $item): string => $item->baselineState,
            $matched['diagnostics'],
        ));
        self::assertSame([], $matched['stale']);

        $stale = $this->manager->apply($this->directory, $this->options(), []);
        self::assertCount(1, $stale['stale']);
        self::assertSame(1, $stale['stale'][0]->occurrence);
    }

    public function testRejectsRemovedDiagnosticCodesInExistingBaselines(): void
    {
        file_put_contents($this->directory.'/baseline.json', json_encode([
            'version' => 1,
            'diagnostics' => [[
                'project' => '.',
                'path' => 'config/services.yaml',
                'code' => 'service.removed',
                'severity' => 'error',
                'source' => 'symfony',
                'message' => 'Removed diagnostic.',
                'fingerprint' => hash('sha256', 'removed'),
                'occurrence' => 1,
            ]],
        ], \JSON_THROW_ON_ERROR));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('invalid diagnostic entry');

        $this->manager->apply($this->directory, $this->options(), []);
    }

    public function testRefreshRequiresAnExistingBaseline(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('--generate-baseline');

        $this->manager->apply($this->directory, $this->options('refresh'), []);
    }

    public function testOnlyOneConcurrentBaselineCreationSucceeds(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('The pcntl extension is required.');
        }

        $barrier = $this->directory.'/barrier';
        mkdir($barrier);
        $pids = [];
        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if (0 === $pid) {
                $this->createBaselineInChild($worker, $barrier);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            self::assertSame($pid, pcntl_waitpid($pid, $status));
            if (!\is_int($status)) {
                throw new \RuntimeException('Unable to read the child process status.');
            }
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $results = array_map(
            static fn (int $worker): string => trim((string) file_get_contents($barrier.'/result-'.$worker)),
            [1, 2],
        );
        sort($results);

        self::assertSame(['created', 'exists'], $results);
    }

    public function testFingerprintSurvivesUnrelatedLineMovementButNotChangedEvidence(): void
    {
        $project = new Project($this->directory, 'file://'.$this->directory);
        $file = new CheckFile(
            $project,
            $this->directory.'/config/services.yaml',
            'config/services.yaml',
            'config/services.yaml',
            'file://'.$this->directory.'/config/services.yaml',
            'yaml',
            false,
        );
        $protocol = static fn (int $line): array => [
            'range' => [
                'start' => ['line' => $line, 'character' => 7],
                'end' => ['line' => $line, 'character' => 14],
            ],
            'severity' => 1,
            'code' => 'service.not_found',
            'source' => 'symfony',
            'message' => 'Service does not exist.',
        ];
        $positions = new PositionConverter();

        $original = CheckDiagnostic::fromProtocol($file, '.', 'prefix missing suffix', $protocol(0), $positions);
        $moved = CheckDiagnostic::fromProtocol($file, '.', "first\nsecond\nprefix missing suffix", $protocol(2), $positions);
        $changed = CheckDiagnostic::fromProtocol($file, '.', 'prefix changed suffix', $protocol(0), $positions);

        self::assertSame($original->fingerprint, $moved->fingerprint);
        self::assertNotSame($original->fingerprint, $changed->fingerprint);
    }

    private function createBaselineInChild(int $worker, string $barrier): never
    {
        $manager = $this->manager(new SynchronizingBaselineFilesystem($barrier));
        try {
            $manager->apply($this->directory, $this->options('create'), []);
            $result = 'created';
        } catch (InvalidConfigurationException $error) {
            $result = str_contains($error->getMessage(), 'already exists') ? 'exists' : $error->getMessage();
        } catch (\Throwable $error) {
            $result = $error::class.': '.$error->getMessage();
        }
        file_put_contents($barrier.'/result-'.$worker, $result);

        exit(0);
    }

    private function manager(Filesystem $filesystem): BaselineManager
    {
        return new BaselineManager(
            new BaselineRepository($filesystem, new BaselineCodec(new DiagnosticCodeRegistry())),
            new BaselineMatcher(new CheckDiagnosticOccurrenceNumberer()),
        );
    }

    private function diagnostic(string $fingerprint): CheckDiagnostic
    {
        return new CheckDiagnostic(
            '.',
            'config/services.yaml',
            'config/services.yaml',
            2,
            4,
            2,
            12,
            1,
            'service.not_found',
            'symfony',
            'Service "app.mailer" does not exist.',
            hash('sha256', $fingerprint),
        );
    }

    private function options(string $mode = 'none'): CheckOptions
    {
        return new CheckOptions(
            'json',
            $this->directory,
            null,
            [],
            [],
            [],
            null,
            'baseline.json',
            $mode,
            false,
            60.0,
            false,
            false,
            false,
        );
    }
}

final class SynchronizingBaselineFilesystem extends Filesystem
{
    public function __construct(private readonly string $barrier)
    {
    }

    /** @param string|iterable<string> $dirs */
    public function mkdir(string|iterable $dirs, int $mode = 0o777): void
    {
        $this->synchronize();
        parent::mkdir($dirs, $mode);
    }

    public function dumpFile(string $filename, $content): void
    {
        $this->synchronize();
        parent::dumpFile($filename, $content);
    }

    private function synchronize(): void
    {
        file_put_contents($this->barrier.'/ready-'.getmypid(), '');
        $deadline = microtime(true) + 5;
        while (\count(glob($this->barrier.'/ready-*') ?: []) < 2) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Timed out waiting for concurrent baseline creation.');
            }
            usleep(1000);
        }
    }
}
