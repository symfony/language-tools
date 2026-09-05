<?php

namespace Symfony\Lsp\Parser\Xml;

final class XmlDiagnostic
{
    public function __construct(
        public readonly string $message,
        public readonly int $startOffset,
        public readonly int $endOffset,
    ) {
    }
}
