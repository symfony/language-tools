<?php

namespace Symfony\Lsp\Index;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 * @phpstan-import-type SourceIndexRecord from SourceIndexReaderInterface
 */
final class SourceIndexFileProcessor
{
    public function __construct(
        private readonly SourceIndexStoreInterface $store,
        private readonly SourceIndexProviderPipeline $providers,
        private readonly PhpRuntimeStructureHasher $runtimeStructureHasher,
    ) {
    }

    /** @param ?SourceIndexRecord $cached */
    public function scan(SourceIndexFileLocation $location, string $languageId, ?array $cached): ?ProcessedSourceIndexFile
    {
        $source = $this->read($location->path);
        if (null === $source) {
            return null;
        }
        if (null !== $cached && $this->isFresh($location->path, $languageId, $source['hash'], $cached['metadata'])) {
            try {
                $this->providers->restore($location->project, $cached['payloads']);
            } catch (\Throwable $error) {
                throw new InvalidSourceIndexEntry(previous: $error);
            }

            return new ProcessedSourceIndexFile($cached['metadata'], $cached['payloads'], false);
        }

        [$document, $runtimeStructure, $metadata] = $this->analyze($location, $languageId, $source);

        return new ProcessedSourceIndexFile(
            $metadata,
            $this->providers->index($location->project, $document),
            true,
        );
    }

    /** @param ?SourceIndexMetadata $cached */
    public function update(SourceIndexFileLocation $location, string $languageId, ?array $cached, bool $indexed): ?UpdatedSourceIndexFile
    {
        $source = $this->read($location->path);
        if (null === $source) {
            return null;
        }
        if ($indexed && null !== $cached && $languageId === $cached['languageId'] && $source['hash'] === $cached['hash']) {
            return new UpdatedSourceIndexFile(SourceFileChange::unchanged(), null, null);
        }

        $previousPayloads = [];
        if (null !== $cached) {
            try {
                $previousPayloads = $this->store->loadPayloads($location->project, $location->relativePath);
            } catch (\UnexpectedValueException) {
            }
        }
        [$document, $runtimeStructure, $metadata] = $this->analyze($location, $languageId, $source);
        $replacement = $this->providers->replace($location->project, $document, $previousPayloads);
        if (null === $cached) {
            $change = SourceFileChange::untracked();
        } elseif (null !== $runtimeStructure->hash && $runtimeStructure->hash === $cached['runtimeStructure']) {
            $change = SourceFileChange::contentOnly();
        } else {
            $change = 'php' === $languageId && !$runtimeStructure->requiresFullTracking && $replacement->factsChanged && [] === $replacement->changedProviders
                ? SourceFileChange::contentOnly()
                : SourceFileChange::factsChanged($replacement->changedProviders);
        }

        return new UpdatedSourceIndexFile($change, $metadata, $replacement->payloads);
    }

    /** @return ?array{text: string, hash: string} */
    private function read(string $path): ?array
    {
        $text = file_get_contents($path);
        if (false === $text) {
            return null;
        }

        return ['text' => $text, 'hash' => hash('sha256', $text)];
    }

    /**
     * @param array{text: string, hash: string} $source
     *
     * @return array{SourceDocument, PhpRuntimeStructureAnalysis, SourceIndexMetadata}
     */
    private function analyze(SourceIndexFileLocation $location, string $languageId, array $source): array
    {
        $document = new SourceDocument($location->uri, $languageId, $source['text']);
        $runtimeStructure = $this->runtimeStructureHasher->analyze($location->relativePath, $source['text']);

        return [$document, $runtimeStructure, $this->metadata($location->path, $languageId, $source['hash'], $runtimeStructure->hash)];
    }

    /** @param SourceIndexMetadata $entry */
    private function isFresh(string $path, string $languageId, string $hash, array $entry): bool
    {
        return $languageId === $entry['languageId']
            && filesize($path) === $entry['size']
            && filemtime($path) === $entry['modifiedAt']
            && $hash === $entry['hash'];
    }

    /** @return SourceIndexMetadata */
    private function metadata(string $path, string $languageId, string $hash, ?string $runtimeStructure): array
    {
        $size = filesize($path);
        $modifiedAt = filemtime($path);
        if (false === $size || false === $modifiedAt) {
            throw new \RuntimeException(\sprintf('Unable to read source metadata for "%s".', $path));
        }

        return [
            'size' => $size,
            'modifiedAt' => $modifiedAt,
            'hash' => $hash,
            'languageId' => $languageId,
            'runtimeStructure' => $runtimeStructure,
        ];
    }
}
