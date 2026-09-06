<?php

namespace Symfony\Lsp\Parser\Xml;

final class XmlAttribute
{
    public function __construct(
        public readonly string $qualifiedName,
        public readonly string $value,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
        public readonly int $valueStartOffset,
        public readonly int $valueEndOffset,
    ) {
    }
}
