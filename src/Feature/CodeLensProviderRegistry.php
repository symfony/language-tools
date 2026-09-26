<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\LspRequestFactory;

final class CodeLensProviderRegistry
{
    /** @param iterable<CodeLensProviderInterface> $providers */
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
    public function codeLenses(array $params): array
    {
        $request = $this->requests->document($params);
        if (null === $request) {
            return [];
        }

        $lenses = [];
        foreach ($this->providers as $provider) {
            array_push($lenses, ...$provider->codeLenses($request));
        }

        return $lenses;
    }
}
