<?php

namespace Symfony\Lsp\Feature;

final class RenameProviderRegistry
{
    /** @param iterable<RenameProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
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
