<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Check\CheckProfile;
use Symfony\Lsp\Check\CheckProfiler;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\UriToPathConverter;

final class CheckProfilerTest extends TestCase
{
    public function testAggregatesProvidersAndKeepsTheTenSlowestFiles(): void
    {
        $profiler = new CheckProfiler(new ProjectConfiguration(new UriToPathConverter(), new AnalysisSettings()));
        $profiler->start(true);
        $project = new Project('/workspace', 'file:///workspace');
        $profiler->recordProjectFiles($project, 11);
        $profiler->recordDiagnosticProviders($project, ['route' => 1_000_000.0, 'template' => 3_000_000.0]);
        $profiler->recordDiagnosticProviders($project, ['route' => 4_000_000.0]);

        for ($index = 1; $index <= 11; ++$index) {
            $profiler->recordDiagnosticFile(
                $project,
                \sprintf('config/file-%02d.yaml', $index),
                (float) hrtime(true) - $index * 10_000_000,
            );
        }

        $profile = $profiler->finish();

        self::assertInstanceOf(CheckProfile::class, $profile);
        self::assertSame(['route', 'template'], array_keys($profile->projects[0]->diagnosticProvidersMilliseconds));
        self::assertSame(5.0, $profile->projects[0]->diagnosticProvidersMilliseconds['route']);
        self::assertCount(10, $profile->projects[0]->slowestFilesMilliseconds);
        self::assertSame('config/file-11.yaml', array_key_first($profile->projects[0]->slowestFilesMilliseconds));
        self::assertArrayNotHasKey('config/file-01.yaml', $profile->projects[0]->slowestFilesMilliseconds);
    }
}
