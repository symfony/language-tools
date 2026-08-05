<?php

namespace Symfony\Lsp\Feature;

final class DefinitionProviderRegistry
{
    /** @param iterable<DefinitionProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function definition(array $params): ?array
    {
        $locations = [];
        $matched = false;
        foreach ($this->providers as $provider) {
            $providedLocations = $provider->definition($params);
            if (null === $providedLocations) {
                continue;
            }

            $matched = true;
            array_push($locations, ...$providedLocations);
        }

        return $matched ? $locations : null;
    }
}
