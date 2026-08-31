<?php

namespace Symfony\Lsp\Index;

final class SourceIndexProviderReplacement
{
    /**
     * @param array<string, string> $payloads
     * @param list<string>          $changedProviders
     */
    public function __construct(
        public readonly array $payloads,
        public readonly bool $factsChanged,
        public readonly array $changedProviders,
    ) {
    }
}
