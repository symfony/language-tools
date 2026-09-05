<?php

namespace Symfony\Lsp\Parser\Xml;

final class XmlAttribute
{
    public readonly ?string $prefix;
    public readonly string $localName;

    public function __construct(
        public readonly string $qualifiedName,
        public readonly string $value,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
        public readonly int $valueStartOffset,
        public readonly int $valueEndOffset,
        public readonly string $quote,
    ) {
        $separator = strpos($qualifiedName, ':');
        $this->prefix = false === $separator ? null : substr($qualifiedName, 0, $separator);
        $this->localName = false === $separator ? $qualifiedName : substr($qualifiedName, $separator + 1);
    }
}
