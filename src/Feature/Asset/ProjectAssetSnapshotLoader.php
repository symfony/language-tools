<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectAssetSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly AssetIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'assets';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $assets = [];
        foreach ($section->items('assets', 'logicalPath', 'sourcePath') as $item) {
            $assets[] = new Asset($item->string('logicalPath'), $item->path('sourcePath'), $item->bool('vendor'));
        }
        $entries = [];
        foreach ($section->items('importMap', 'name', 'path') as $item) {
            $entries[] = new ImportMapEntry(
                $item->string('name'),
                $item->string('path'),
                $item->bool('entrypoint'),
                $item->optionalString('version'),
            );
        }
        $this->indexes->forProject($project)->replace(
            $assets,
            $entries,
            $section->complete('assets'),
            $section->complete('importMap'),
        );
    }
}
