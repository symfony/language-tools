<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\LspRequestFactory;

final class CodeActionProviderRegistry
{
    /** @param iterable<CodeActionProviderInterface> $providers */
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
    public function actions(array $params): array
    {
        $request = $this->requests->codeAction($params);
        if (null === $request) {
            return [];
        }

        $actions = [];
        foreach ($this->providers as $provider) {
            array_push($actions, ...$provider->actions($request));
        }

        return $actions;
    }
}
