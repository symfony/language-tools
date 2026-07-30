<?php

namespace Symfony\Lsp\Feature;

final class CompletionProviderRegistry
{
    /** @var list<CompletionProviderInterface> */
    private array $providers;

    public function __construct(CompletionProviderInterface ...$providers)
    {
        $this->providers = array_values($providers);
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function complete(array $params): ?array
    {
        $items = [];
        $matched = false;
        foreach ($this->providers as $provider) {
            $providedItems = $provider->complete($params);
            if (null === $providedItems) {
                continue;
            }

            $matched = true;
            array_push($items, ...$providedItems);
        }

        return $matched ? $items : null;
    }
}
