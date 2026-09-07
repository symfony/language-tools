<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ScenarioManifest
{
    /**
     * @param list<array<string, mixed>> $scenarios
     * @param list<array<string, mixed>> $diagnostics
     */
    public function __construct(
        public readonly string $revision,
        public readonly array $scenarios,
        public readonly array $diagnostics,
    ) {
    }
}
