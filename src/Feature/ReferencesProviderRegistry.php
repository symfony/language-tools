<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\LspRequestFactory;

final class ReferencesProviderRegistry
{
    /** @param iterable<ReferencesProviderInterface> $providers */
    public function __construct(
        private readonly LspRequestFactory $requests,
        private readonly iterable $providers,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>
     */
    public function references(array $params): array
    {
        $request = $this->requests->references($params);
        if (null === $request) {
            return [];
        }

        $locations = [];
        foreach ($this->providers as $provider) {
            array_push($locations, ...$provider->references($request));
        }

        return $locations;
    }
}
