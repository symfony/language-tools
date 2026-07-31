<?php

namespace Symfony\Lsp\Tests\Feature\Environment;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Feature\Environment\ProjectEnvironmentSnapshotLoader;
use Symfony\Lsp\Project\Project;

final class ProjectEnvironmentSnapshotLoaderTest extends TestCase
{
    public function testLoadsProcessorNamesAndTypes(): void
    {
        $indexes = new EnvironmentIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        (new ProjectEnvironmentSnapshotLoader($indexes))->load($project, ['sections' => ['environment' => ['complete' => true, 'processors' => [
            ['name' => 'json', 'type' => 'array'],
            ['name' => 'int', 'type' => 'int'],
        ]]]]);

        self::assertSame(['int' => 'int', 'json' => 'array'], $indexes->forProject($project)->processors());
        self::assertTrue($indexes->forProject($project)->processorsComplete());
    }
}
