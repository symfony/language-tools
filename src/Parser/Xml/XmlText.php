<?php

namespace Symfony\Lsp\Parser\Xml;

final class XmlText
{
    public function __construct(
        public readonly ?int $parentIdentity,
        public readonly string $raw,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly XmlTextKind $kind = XmlTextKind::Text,
    ) {
    }
}
