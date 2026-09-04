<?php

namespace Symfony\Lsp\Feature\Configuration;

final class PhpConfigurationOccurrence
{
    /**
     * @param list<string> $path
     * @param list<string> $schemaPath
     * @param list<string> $builderPath
     * @param list<string> $builderSchemaPath
     */
    public function __construct(
        public readonly array $path,
        public readonly array $schemaPath,
        public readonly array $builderPath,
        public readonly array $builderSchemaPath,
        public readonly string $argument,
        public readonly int $startOffset,
        public readonly int $endOffset,
    ) {
    }
}
