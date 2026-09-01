<?php

namespace Symfony\Lsp\Feature\Configuration;

final class XmlConfigurationAttribute
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly int $startOffset,
        public readonly int $endOffset,
    ) {
    }
}
