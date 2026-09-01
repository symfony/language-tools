<?php

namespace Symfony\Lsp\Feature\Configuration;

final class XmlConfigurationStructureError
{
    public function __construct(
        public readonly string $message,
        public readonly int $startOffset,
        public readonly int $endOffset,
    ) {
    }
}
