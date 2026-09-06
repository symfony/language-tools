<?php

namespace Symfony\Lsp\Parser\Xml;

final class XmlElementEnd
{
    public function __construct(
        public readonly ?int $identity,
        public readonly int $startOffset,
    ) {
    }
}
