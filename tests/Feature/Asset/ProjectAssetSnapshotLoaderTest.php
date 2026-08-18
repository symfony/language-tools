<?php

namespace Symfony\Lsp\Tests\Feature\Asset;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Asset\AssetIndexRegistry;
use Symfony\Lsp\Feature\Asset\ProjectAssetSnapshotLoader;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ProjectAssetSnapshotLoaderTest extends TestCase
{
    public function testLoadsAssetsAndImportmapEntries(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $indexes = new AssetIndexRegistry();
        (new ProjectAssetSnapshotLoader($indexes, new ContainerPathMapper(new RuntimeConfiguration())))->load($project, ['sections' => ['assets' => [
            'assetsComplete' => true,
            'importMapComplete' => true,
            'assets' => [[
                'logicalPath' => 'app.js',
                'sourcePath' => '/workspace/assets/app.js',
                'vendor' => false,
            ]],
            'importMap' => [[
                'name' => 'app',
                'path' => './assets/app.js',
                'entrypoint' => true,
                'version' => null,
            ]],
        ]]]);

        $index = $indexes->forProject($project);
        self::assertTrue($index->assetsComplete());
        self::assertTrue($index->importMapComplete());
        self::assertSame('/workspace/assets/app.js', $index->asset('app.js')?->sourcePath());
        self::assertTrue($index->importMapEntry('app')?->isEntrypoint());
    }

    public function testMapsContainerSourcePathsToTheHost(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['containerProjectRoot' => '/app']);
        $indexes = new AssetIndexRegistry();
        (new ProjectAssetSnapshotLoader($indexes, new ContainerPathMapper($configuration)))->load($project, ['sections' => ['assets' => [
            'assetsComplete' => true,
            'importMapComplete' => true,
            'assets' => [[
                'logicalPath' => 'app.js',
                'sourcePath' => '/app/assets/app.js',
                'vendor' => false,
            ]],
            'importMap' => [[
                'name' => 'app',
                'path' => './assets/app.js',
                'entrypoint' => true,
                'version' => null,
            ]],
        ]]]);

        $index = $indexes->forProject($project);
        self::assertSame('/workspace/assets/app.js', $index->asset('app.js')?->sourcePath());
        self::assertSame('./assets/app.js', $index->importMapEntry('app')?->path());
    }
}
