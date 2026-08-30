<?php

namespace Symfony\Lsp\Feature\Stimulus;

final class StimulusController
{
    /**
     * @param list<string> $actions
     * @param list<string> $targets
     * @param list<string> $values
     * @param list<string> $outlets
     * @param list<string> $classes
     */
    public function __construct(
        public readonly string $name,
        public readonly string $sourcePath,
        public readonly bool $lazy,
        public readonly bool $vendor,
        public readonly array $actions,
        public readonly array $targets,
        public readonly array $values,
        public readonly array $outlets,
        public readonly array $classes,
    ) {
    }
}
