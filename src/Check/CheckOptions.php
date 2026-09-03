<?php

namespace Symfony\Lsp\Check;

final class CheckOptions
{
    /**
     * @param list<string>         $selectors
     * @param list<string>         $projectRoots
     * @param array<string, mixed> $overrides
     * @param list<string>|null    $blockingCodes
     */
    public function __construct(
        public readonly string $format,
        public readonly string $workspace,
        public readonly ?string $configurationPath,
        public readonly array $selectors,
        public readonly array $projectRoots,
        public readonly array $overrides,
        public readonly ?array $blockingCodes,
        public readonly ?string $baselinePath,
        public readonly string $baselineMode,
        public readonly bool $strictBaseline,
        public readonly float $timeout,
        public readonly bool $verbose,
        public readonly bool $profile,
        public readonly bool $listCodes,
        public readonly bool $help,
    ) {
    }
}
