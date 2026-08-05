<?php

namespace Symfony\Lsp\Feature;

final class CodeLensProviderRegistry
{
    /** @param iterable<CodeLensProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>
     */
    public function codeLenses(array $params): array
    {
        $lenses = [];
        foreach ($this->providers as $provider) {
            $provided = $provider->codeLenses($params);
            if (null !== $provided) {
                array_push($lenses, ...$provided);
            }
        }

        return $lenses;
    }
}
