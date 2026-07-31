<?php

namespace Symfony\Lsp\Feature;

final class CodeLensProviderRegistry
{
    /** @var list<CodeLensProviderInterface> */
    private array $providers;

    public function __construct(CodeLensProviderInterface ...$providers)
    {
        $this->providers = array_values($providers);
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
