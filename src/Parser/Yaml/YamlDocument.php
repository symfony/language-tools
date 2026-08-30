<?php

namespace Symfony\Lsp\Parser\Yaml;

final class YamlDocument
{
    /**
     * @param list<YamlMapping> $mappings
     * @param list<YamlScalar>  $scalars
     */
    public function __construct(
        public readonly array $mappings,
        public readonly array $scalars,
    ) {
    }
}
