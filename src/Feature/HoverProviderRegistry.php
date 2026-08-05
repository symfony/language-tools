<?php

namespace Symfony\Lsp\Feature;

final class HoverProviderRegistry
{
    /** @param iterable<HoverProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>|null
     */
    public function hover(array $params): ?array
    {
        foreach ($this->providers as $provider) {
            if (null !== $hover = $provider->hover($params)) {
                return $hover;
            }
        }

        return null;
    }
}
