<?php

namespace Symfony\Lsp\Parser\Xml;

final class XmlOpaque
{
    public function __construct(
        public readonly ?int $parentIdentity,
        public readonly XmlOpaqueKind $kind,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly int $contentStartOffset,
        public readonly int $contentEndOffset,
    ) {
    }
}
