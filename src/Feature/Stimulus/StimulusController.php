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
        private readonly string $name,
        private readonly string $sourcePath,
        private readonly bool $lazy,
        private readonly bool $vendor,
        private readonly array $actions,
        private readonly array $targets,
        private readonly array $values,
        private readonly array $outlets,
        private readonly array $classes,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    public function isLazy(): bool
    {
        return $this->lazy;
    }

    public function isVendor(): bool
    {
        return $this->vendor;
    }

    /** @return list<string> */
    public function actions(): array
    {
        return $this->actions;
    }

    /** @return list<string> */
    public function targets(): array
    {
        return $this->targets;
    }

    /** @return list<string> */
    public function values(): array
    {
        return $this->values;
    }

    /** @return list<string> */
    public function outlets(): array
    {
        return $this->outlets;
    }

    /** @return list<string> */
    public function classes(): array
    {
        return $this->classes;
    }
}
