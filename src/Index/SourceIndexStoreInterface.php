<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;

/**
 * @phpstan-type SourceIndexMetadata array{size: int, modifiedAt: int, hash: string, languageId: string, runtimeStructure: ?string}
 */
interface SourceIndexStoreInterface
{
    /**
     * @return array<string, SourceIndexMetadata>
     */
    public function loadMetadata(Project $project): array;

    public function beginRead(Project $project): SourceIndexReaderInterface;

    /**
     * The provider payloads of a single indexed file, read on demand so the
     * whole index never has to be materialized in memory.
     *
     * @return array<string, string>
     */
    public function loadPayloads(Project $project, string $relativePath): array;

    /**
     * Streams a full replacement of the project index and swaps it in
     * atomically on commit.
     */
    public function beginRewrite(Project $project): SourceIndexWriterInterface;

    /**
     * @param SourceIndexMetadata   $metadata
     * @param array<string, string> $payloads
     */
    public function append(Project $project, string $relativePath, array $metadata, array $payloads): void;

    public function appendDeletion(Project $project, string $relativePath): void;
}
