<?php

namespace Symfony\Lsp\Runtime;

final readonly class RuntimeRefreshPlan
{
    /** @param list<string>|null $sections */
    public function __construct(
        private RuntimeRefreshMode $mode = RuntimeRefreshMode::Reuse,
        private ?array $sections = null,
        private bool $preserveContainer = false,
    ) {
    }

    public function mode(): RuntimeRefreshMode
    {
        return $this->mode;
    }

    /** @return list<string>|null */
    public function sections(): ?array
    {
        return $this->sections;
    }

    public function preservesContainer(): bool
    {
        return $this->preserveContainer;
    }

    public function combine(self $plan): self
    {
        $sections = null;
        if (null !== $this->sections && null !== $plan->sections) {
            $sections = array_values(array_unique([...$this->sections, ...$plan->sections]));
        }

        return new self(
            $this->mode->combine($plan->mode),
            $sections,
            $this->preserveContainer && $plan->preserveContainer,
        );
    }
}
