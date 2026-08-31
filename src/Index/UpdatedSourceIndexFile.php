<?php

namespace Symfony\Lsp\Index;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class UpdatedSourceIndexFile
{
    /**
     * @param ?SourceIndexMetadata   $metadata
     * @param ?array<string, string> $payloads
     */
    public function __construct(
        public readonly SourceFileChange $change,
        public readonly ?array $metadata,
        public readonly ?array $payloads,
    ) {
    }
}
