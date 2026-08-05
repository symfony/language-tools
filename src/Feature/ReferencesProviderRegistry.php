<?php

namespace Symfony\Lsp\Feature;

final class ReferencesProviderRegistry
{
    /** @param iterable<ReferencesProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
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
