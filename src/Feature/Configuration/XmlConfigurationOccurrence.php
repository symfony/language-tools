<?php

namespace Symfony\Lsp\Feature\Configuration;

final class XmlConfigurationOccurrence
{
    /**
     * @param list<string>|null               $path
     * @param list<XmlConfigurationAttribute> $attributes
     */
    public function __construct(
        public readonly ?array $path,
        public readonly string $name,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly array $attributes,
    ) {
    }
}
