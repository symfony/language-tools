<?php

namespace Symfony\Lsp\Parser\Yaml;

final class YamlMapping
{
    /**
     * @param list<string> $path
     * @param list<int>    $sequenceDepths path indices entered through a sequence item
     */
    public function __construct(
        private readonly array $path,
        private readonly string $value,
        private readonly int $keyStartByte,
        private readonly int $keyEndByte,
        private readonly int $valueStartByte,
        private readonly int $valueEndByte,
        private readonly array $sequenceDepths,
        private readonly string $scope,
    ) {
    }

    /** @return list<string> */
    public function path(): array
    {
        return $this->path;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function keyStartByte(): int
    {
        return $this->keyStartByte;
    }

    public function keyEndByte(): int
    {
        return $this->keyEndByte;
    }

    public function valueStartByte(): int
    {
        return $this->valueStartByte;
    }

    public function valueEndByte(): int
    {
        return $this->valueEndByte;
    }

    public function isSequenceItem(): bool
    {
        return [] !== $this->sequenceDepths;
    }

    /** @return list<int> */
    public function sequenceDepths(): array
    {
        return $this->sequenceDepths;
    }

    public function scope(): string
    {
        return $this->scope;
    }
}
