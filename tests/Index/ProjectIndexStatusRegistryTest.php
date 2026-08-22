<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;

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
}
