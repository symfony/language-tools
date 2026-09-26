<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\LspRequestFactory;

final class CompletionProviderRegistry
{
    /** @param iterable<CompletionProviderInterface> $providers */
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
    public function complete(array $params): array
    {
        $request = $this->requests->positioned($params);
        if (null === $request) {
            return [];
        }

        $items = [];
        foreach ($this->providers as $provider) {
            array_push($items, ...$provider->complete($request));
        }

        return $items;
    }
}
