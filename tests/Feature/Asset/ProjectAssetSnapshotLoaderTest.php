<?php

namespace Symfony\Lsp\Tests\Feature\Asset;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Asset\AssetIndexRegistry;
use Symfony\Lsp\Feature\Asset\ProjectAssetSnapshotLoader;
use Symfony\Lsp\Project\Project;

final class ProjectAssetSnapshotLoaderTest extends TestCase
{
    public function testLoadsAssetsAndImportmapEntries(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $indexes = new AssetIndexRegistry();
        (new ProjectAssetSnapshotLoader($indexes))->load($project, ['sections' => ['assets' => [
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
}
