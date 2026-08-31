<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;

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
    public function scan(Project $project, string $relativePath, string $path, string $languageId, ?array $cached): ?ProcessedSourceIndexFile
    {
        $source = $this->read($path);
        if (null === $source) {
            return null;
        }
        if (null !== $cached && $this->isFresh($path, $languageId, $source['hash'], $cached['metadata'])) {
            try {
                $this->providers->restore($project, $cached['payloads']);
            } catch (\Throwable $error) {
                throw new InvalidSourceIndexEntry(previous: $error);
            }

            return new ProcessedSourceIndexFile($cached['metadata'], $cached['payloads'], false);
        }

        [$document, $runtimeStructure, $metadata] = $this->analyze($project, $relativePath, $path, $languageId, $source);

        return new ProcessedSourceIndexFile(
            $metadata,
            $this->providers->index($project, $document),
            true,
        );
    }

    /** @param ?SourceIndexMetadata $cached */
    public function update(Project $project, string $relativePath, string $path, string $languageId, ?array $cached, bool $indexed): ?UpdatedSourceIndexFile
    {
        $source = $this->read($path);
        if (null === $source) {
            return null;
        }
        if ($indexed && null !== $cached && $languageId === $cached['languageId'] && $source['hash'] === $cached['hash']) {
            return new UpdatedSourceIndexFile(SourceFileChange::unchanged(), null, null);
        }

        $previousPayloads = [];
        if (null !== $cached) {
            try {
                $previousPayloads = $this->store->loadPayloads($project, $relativePath);
            } catch (\UnexpectedValueException) {
            }
        }
        [$document, $runtimeStructure, $metadata] = $this->analyze($project, $relativePath, $path, $languageId, $source);
        $replacement = $this->providers->replace($project, $document, $previousPayloads);
        $change = null === $cached ? SourceFileChange::untracked() : SourceFileChange::factsChanged([]);
        if (null !== $runtimeStructure->hash && $runtimeStructure->hash === ($cached['runtimeStructure'] ?? null)) {
            $change = SourceFileChange::contentOnly();
        }
        if (null !== $cached && $change->requiresRuntimeRefresh()) {
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
    private function analyze(Project $project, string $relativePath, string $path, string $languageId, array $source): array
    {
        $document = new SourceDocument($this->uri($project, $relativePath), $languageId, $source['text']);
        $runtimeStructure = $this->runtimeStructureHasher->analyze($relativePath, $source['text']);

        return [$document, $runtimeStructure, $this->metadata($path, $languageId, $source['hash'], $runtimeStructure->hash)];
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

    private function uri(Project $project, string $relativePath): string
    {
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return rtrim($project->rootUri, '/').'/'.$encodedPath;
    }
}
