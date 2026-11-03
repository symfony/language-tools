<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\LspRequestFactory;

final class DocumentColorProviderRegistry
{
    /** @param iterable<DocumentColorProviderInterface> $providers */
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
    public function colors(array $params): array
    {
        $request = $this->requests->document($params);
        if (null === $request) {
            return [];
        }

        $colors = [];
        foreach ($this->providers as $provider) {
            array_push($colors, ...$provider->colors($request));
        }

        return $colors;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>
     */
    public function presentations(array $params): array
    {
        $request = $this->requests->colorPresentation($params);
        if (null === $request) {
            return [];
        }

        $presentations = [];
        foreach ($this->providers as $provider) {
            array_push($presentations, ...$provider->presentations($request));
        }

        return $presentations;
    }
}
