<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectAssetSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly AssetIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'assets';
    }

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $section = \is_array($sections) ? ($sections['assets'] ?? null) : null;
        if (!\is_array($section)) {
            return;
        }
        $assets = [];
        foreach (\is_array($section['assets'] ?? null) ? $section['assets'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['logicalPath'] ?? null) || !\is_string($item['sourcePath'] ?? null)) {
                continue;
            }
            $assets[] = new Asset($item['logicalPath'], $item['sourcePath'], true === ($item['vendor'] ?? false));
        }
        $entries = [];
        foreach (\is_array($section['importMap'] ?? null) ? $section['importMap'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['name'] ?? null) || !\is_string($item['path'] ?? null)) {
                continue;
            }
            $entries[] = new ImportMapEntry(
                $item['name'],
                $item['path'],
                true === ($item['entrypoint'] ?? false),
                \is_string($item['version'] ?? null) ? $item['version'] : null,
            );
        }
        $this->indexes->forProject($project)->replace(
            $assets,
            $entries,
            true === ($section['assetsComplete'] ?? false),
            true === ($section['importMapComplete'] ?? false),
        );
    }
}
