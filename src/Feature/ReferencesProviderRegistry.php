<?php

namespace Symfony\Lsp\Feature;

final class ReferencesProviderRegistry
{
    /** @var list<ReferencesProviderInterface> */
    private array $providers;

    public function __construct(ReferencesProviderInterface ...$providers)
    {
        $this->providers = array_values($providers);
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function references(array $params): ?array
    {
        $locations = [];
        $matched = false;
        foreach ($this->providers as $provider) {
            $providedLocations = $provider->references($params);
            if (null === $providedLocations) {
                continue;
            }

            $matched = true;
            array_push($locations, ...$providedLocations);
        }

        return $matched ? $locations : null;
    }
}
