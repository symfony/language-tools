<?php

namespace Symfony\Lsp\Parser\Yaml;

final class YamlMapping
{
    /**
     * @param list<string> $path
     * @param list<int>    $sequenceDepths path indices entered through a sequence item
     */
    public function __construct(
        public readonly array $path,
        public readonly string $value,
        public readonly int $keyStartByte,
        public readonly int $keyEndByte,
        public readonly int $valueStartByte,
        public readonly int $valueEndByte,
        public readonly array $sequenceDepths,
        public readonly string $scope,
    ) {
    }

    public function isSequenceItem(): bool
    {
        return [] !== $this->sequenceDepths;
    }
}
