<?php

namespace Symfony\Lsp\Feature;

final class DefinitionProviderRegistry
{
    /** @var list<DefinitionProviderInterface> */
    private array $providers;

    public function __construct(DefinitionProviderInterface ...$providers)
    {
        $this->providers = array_values($providers);
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
