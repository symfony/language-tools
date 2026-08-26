<?php

namespace Symfony\Lsp\Index;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 *
 * @phpstan-type SourceIndexRecord array{metadata: SourceIndexMetadata, payloads: array<string, string>}
 */
interface SourceIndexReaderInterface
{
    public function hasRecords(): bool;

    /**
     * @return iterable<string, SourceIndexRecord>
     */
    public function records(): iterable;

    public function close(): void;
}
