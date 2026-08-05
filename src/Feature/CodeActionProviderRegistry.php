<?php

namespace Symfony\Lsp\Feature;

final class CodeActionProviderRegistry
{
    /** @param iterable<CodeActionProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>
     */
    public function actions(array $params): array
    {
        $actions = [];
        foreach ($this->providers as $provider) {
            $provided = $provider->actions($params);
            if (null !== $provided) {
                array_push($actions, ...$provided);
            }
        }

        return $actions;
    }
}
