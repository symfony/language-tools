<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\LspRequestFactory;

final class DocumentLinkProviderRegistry
{
    /** @param iterable<DocumentLinkProviderInterface> $providers */
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
    public function links(array $params): array
    {
        $request = $this->requests->document($params);
        if (null === $request) {
            return [];
        }

        $links = [];
        foreach ($this->providers as $provider) {
            array_push($links, ...$provider->links($request));
        }

        return $links;
    }
}
