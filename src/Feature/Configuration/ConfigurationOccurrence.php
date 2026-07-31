<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\Range;

final class ConfigurationOccurrence
{
    /** @param list<string> $path */
    public function __construct(
        private readonly array $path,
        private readonly string $value,
        private readonly Range $keyRange,
        private readonly Range $valueRange,
        private readonly bool $sequenceItem,
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

    public function keyRange(): Range
    {
        return $this->keyRange;
    }

    public function valueRange(): Range
    {
        return $this->valueRange;
    }

    public function sequenceItem(): bool
    {
        return $this->sequenceItem;
    }

    public function scope(): string
    {
        return $this->scope;
    }
}
