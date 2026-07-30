<?php

namespace Symfony\Lsp\Feature;

final class RenameProviderRegistry
{
    /** @var list<RenameProviderInterface> */
    private array $providers;

    public function __construct(RenameProviderInterface ...$providers)
    {
        $this->providers = array_values($providers);
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>|null
     */
    public function prepare(array $params): ?array
    {
        foreach ($this->providers as $provider) {
            if (null !== $result = $provider->prepare($params)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>|null
     */
    public function rename(array $params): ?array
    {
        foreach ($this->providers as $provider) {
            if (null !== $result = $provider->rename($params)) {
                return $result;
            }
        }

        return null;
    }
}
