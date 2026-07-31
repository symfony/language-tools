<?php

namespace Symfony\Lsp\Feature;

final class DocumentLinkProviderRegistry
{
    /** @var list<DocumentLinkProviderInterface> */
    private array $providers;

    public function __construct(DocumentLinkProviderInterface ...$providers)
    {
        $this->providers = array_values($providers);
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
