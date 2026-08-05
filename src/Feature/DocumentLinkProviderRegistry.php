<?php

namespace Symfony\Lsp\Feature;

final class DocumentLinkProviderRegistry
{
    /** @param iterable<DocumentLinkProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function links(array $params): ?array
    {
        $links = [];
        $matched = false;
        foreach ($this->providers as $provider) {
            $providedLinks = $provider->links($params);
            if (null === $providedLinks) {
                continue;
            }
            $matched = true;
            array_push($links, ...$providedLinks);
        }

        return $matched ? $links : null;
    }
}
