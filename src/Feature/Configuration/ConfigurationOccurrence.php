<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\Range;

final class ConfigurationOccurrence
{
    /**
     * @param list<string> $path
     * @param list<int>    $sequenceDepths
     * @param list<int>    $literalDepths
     */
    public function __construct(
        public readonly array $path,
        public readonly string $value,
        public readonly Range $keyRange,
        public readonly Range $valueRange,
        public readonly array $sequenceDepths,
        public readonly string $scope,
        public readonly array $literalDepths = [],
    ) {
    }

    public function sequenceItem(): bool
    {
        return [] !== $this->sequenceDepths;
    }
}
