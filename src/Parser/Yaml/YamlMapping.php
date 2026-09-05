<?php

namespace Symfony\Lsp\Parser\Yaml;

final class YamlMapping
{
    /** @var list<int> path indices entered through a sequence item */
    public readonly array $sequenceDepths;

    /**
     * @param list<string>           $path
     * @param list<YamlSequenceItem> $sequence
     */
    public function __construct(
        public readonly array $path,
        public readonly string $value,
        public readonly int $keyStartByte,
        public readonly int $keyEndByte,
        public readonly int $valueStartByte,
        public readonly int $valueEndByte,
        public readonly array $sequence,
        public readonly string $scope,
    ) {
        $this->sequenceDepths = array_values(array_unique(array_map(static fn (YamlSequenceItem $item): int => $item->pathDepth, $sequence)));
    }

    public function isSequenceItem(): bool
    {
        return [] !== $this->sequenceDepths;
    }
}
