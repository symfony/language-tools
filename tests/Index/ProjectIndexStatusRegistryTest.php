<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotState;

final class ProjectIndexStatusRegistryTest extends TestCase
{
    public function testDoesNotExposeFailureDetails(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $statuses = new ProjectIndexStatusRegistry();

        $statuses->sourceFailed($project);
        $statuses->runtimeFailed($project);

        self::assertSame([
            'root' => '/workspace',
            'source' => ['state' => 'failed', 'error' => 'Source indexing failed.'],
            'runtime' => ['state' => 'failed', 'error' => 'Runtime indexing failed.'],
        ], $statuses->status($project));
    }

    public function testExposesTheBootstrapFailureStage(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $statuses = new ProjectIndexStatusRegistry();

        $statuses->runtimeFailed($project, 'bootstrap');

        self::assertSame(
            ['state' => 'failed', 'error' => 'The application failed to boot.', 'stage' => 'bootstrap'],
            $statuses->status($project)['runtime'],
        );
    }

    public function testPreservesRuntimeTimingsUntilTheNextIndexingStarts(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $statuses = new ProjectIndexStatusRegistry();
        $timings = [
            'bootstrapMilliseconds' => 1.0,
            'kernelMilliseconds' => 2.0,
            'sectionsMilliseconds' => ['routes' => 3.0],
            'shutdownMilliseconds' => 4.0,
            'totalMilliseconds' => 10.0,
        ];

        $statuses->runtimeIndexing($project);
        $statuses->runtimeTimings($project, $timings);
        $statuses->runtimeReady($project);

        self::assertSame(['state' => 'ready', 'timings' => $timings], $statuses->status($project)['runtime']);

        $statuses->runtimeIndexing($project);

        self::assertSame(['state' => 'indexing'], $statuses->status($project)['runtime']);
    }

    public function testReportsRestoredRuntimeMetadataAsStale(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $snapshots = new RuntimeSnapshotState();
        $statuses = new ProjectIndexStatusRegistry($snapshots);
        $snapshots->restore($project, '2026-08-25T20:15:00+00:00');

        $statuses->runtimeFailed($project, 'bootstrap');

        self::assertSame([
            'state' => 'stale',
            'lastSuccessfulAt' => '2026-08-25T20:15:00+00:00',
            'error' => 'The application failed to boot.',
            'stage' => 'bootstrap',
        ], $statuses->status($project)['runtime']);
    }
}
