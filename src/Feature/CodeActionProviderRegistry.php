<?php

namespace Symfony\Lsp\Feature;

final class CodeActionProviderRegistry
{
    /** @var list<CodeActionProviderInterface> */
    private array $providers;

    public function __construct(CodeActionProviderInterface ...$providers)
    {
        $this->providers = array_values($providers);
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
