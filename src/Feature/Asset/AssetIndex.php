<?php

namespace Symfony\Lsp\Feature\Asset;

final class AssetIndex
{
    /** @var array<string, Asset> */
    private array $assets = [];
    /** @var array<string, ImportMapEntry> */
    private array $importMapEntries = [];
    private bool $assetsComplete = false;
    private bool $importMapComplete = false;

    /**
     * @param list<Asset>          $assets
     * @param list<ImportMapEntry> $importMapEntries
     */
    public function replace(array $assets, array $importMapEntries, bool $assetsComplete, bool $importMapComplete): void
    {
        $this->assets = [];
        foreach ($assets as $asset) {
            $this->assets[$asset->logicalPath()] = $asset;
        }
        ksort($this->assets);
        $this->importMapEntries = [];
        foreach ($importMapEntries as $entry) {
            $this->importMapEntries[$entry->name()] = $entry;
        }
        ksort($this->importMapEntries);
        $this->assetsComplete = $assetsComplete;
        $this->importMapComplete = $importMapComplete;
    }

    /** @return list<Asset> */
    public function assets(): array
    {
        return array_values($this->assets);
    }

    public function asset(string $logicalPath): ?Asset
    {
        return $this->assets[ltrim($logicalPath, '/')] ?? null;
    }

    /** @return list<ImportMapEntry> */
    public function importMapEntries(): array
    {
        return array_values($this->importMapEntries);
    }

    public function importMapEntry(string $name): ?ImportMapEntry
    {
        return $this->importMapEntries[$name] ?? null;
    }

    public function assetsComplete(): bool
    {
        return $this->assetsComplete;
    }

    public function importMapComplete(): bool
    {
        return $this->importMapComplete;
    }
}
