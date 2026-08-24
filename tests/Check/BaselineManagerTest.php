<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Check\BaselineManager;
use Symfony\Lsp\Check\CheckDiagnostic;
use Symfony\Lsp\Check\CheckFile;
use Symfony\Lsp\Check\CheckOptions;
use Symfony\Lsp\Check\DiagnosticCodeRegistry;
use Symfony\Lsp\Document\PositionConverter;
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
        $this->manager = new BaselineManager(new Filesystem(), new DiagnosticCodeRegistry());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
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

    public function testFingerprintSurvivesUnrelatedLineMovementButNotChangedEvidence(): void
    {
        $project = new Project($this->directory, 'file://'.$this->directory, '^8.0');
        $file = new CheckFile(
            $project,
            $this->directory.'/config/services.yaml',
            'config/services.yaml',
            'config/services.yaml',
            'file://'.$this->directory.'/config/services.yaml',
            'yaml',
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
        );
    }
}
