<?php

namespace Symfony\Lsp\Parser\Yaml;

final class YamlSequenceItem
{
    public function __construct(
        public readonly int $pathDepth,
        public readonly int $index,
    ) {
    }
}
