<?php

namespace Symfony\Lsp\Index;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class ProcessedSourceIndexFile
{
    /**
     * @param SourceIndexMetadata   $metadata
     * @param array<string, string> $payloads
     */
    public function __construct(
        public readonly array $metadata,
        public readonly array $payloads,
        public readonly bool $parsed,
    ) {
    }
}
