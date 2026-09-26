<?php

namespace Symfony\Lsp\Runtime;

final readonly class RuntimeRefreshPlan
{
    /** @param list<string> $sections The sections to refresh, or an empty list for every section */
    private function __construct(
        private RuntimeRefreshMode $mode,
        private array $sections,
    ) {
    }

    /** @param non-empty-list<string> $sections */
    public static function preserve(array $sections): self
    {
        return new self(RuntimeRefreshMode::Preserve, $sections);
    }

    /** @param list<string> $sections */
    public static function reuse(array $sections = []): self
    {
        return new self(RuntimeRefreshMode::Reuse, $sections);
    }

    /** @param list<string> $sections */
    public static function rebuild(array $sections = []): self
    {
        return new self(RuntimeRefreshMode::Rebuild, $sections);
    }

    public function mode(): RuntimeRefreshMode
    {
        return $this->mode;
    }

    /** @return list<string> */
    public function sections(): array
    {
        return $this->sections;
    }

    public function refreshesEverySection(): bool
    {
        return [] === $this->sections;
    }

    public function combine(self $plan): self
    {
        return new self(
            $this->mode->combine($plan->mode),
            $this->refreshesEverySection() || $plan->refreshesEverySection()
                ? []
                : array_values(array_unique([...$this->sections, ...$plan->sections])),
        );
    }
}
