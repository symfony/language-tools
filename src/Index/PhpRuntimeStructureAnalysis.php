<?php

namespace Symfony\Lsp\Index;

final class PhpRuntimeStructureAnalysis
{
    public function __construct(
        public readonly ?string $hash,
        public readonly bool $requiresFullTracking,
    ) {
    }
}
