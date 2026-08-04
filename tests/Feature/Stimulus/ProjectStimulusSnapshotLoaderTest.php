<?php

namespace Symfony\Lsp\Tests\Feature\Stimulus;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Stimulus\ProjectStimulusSnapshotLoader;
use Symfony\Lsp\Feature\Stimulus\StimulusController;
use Symfony\Lsp\Feature\Stimulus\StimulusIndexRegistry;
use Symfony\Lsp\Project\Project;

final class ProjectStimulusSnapshotLoaderTest extends TestCase
{
    public function testLoadsNormalizedStimulusMetadata(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $indexes = new StimulusIndexRegistry();
        (new ProjectStimulusSnapshotLoader($indexes))->load($project, ['sections' => ['stimulus' => [
            'complete' => true,
            'controllers' => [[
                'name' => 'search',
                'sourcePath' => '/workspace/assets/controllers/search_controller.js',
                'lazy' => true,
                'vendor' => false,
                'actions' => ['open'],
                'targets' => ['results'],
                'values' => ['url'],
                'outlets' => ['dialog'],
                'classes' => ['loading'],
            ]],
        ]]]);

        $index = $indexes->forProject($project);
        self::assertTrue($index->isComplete());
        $controller = $index->controller('search');
        self::assertInstanceOf(StimulusController::class, $controller);
        self::assertSame('/workspace/assets/controllers/search_controller.js', $controller->sourcePath());
        self::assertTrue($controller->isLazy());
        self::assertSame(['open'], $controller->actions());
        self::assertSame(['results'], $controller->targets());
        self::assertSame(['url'], $controller->values());
        self::assertSame(['dialog'], $controller->outlets());
        self::assertSame(['loading'], $controller->classes());
    }
}
