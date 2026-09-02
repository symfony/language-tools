<?php

namespace Symfony\Lsp\Feature;

use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcError;
use Symfony\Lsp\Index\SourceOverlayHealthRegistry;

final class RenameProviderRegistry
{
    /** @param iterable<RenameProviderInterface> $providers */
    public function __construct(
        private readonly SourceOverlayHealthRegistry $overlayHealth,
        private readonly iterable $providers,
    ) {
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
            if (null === $result = $provider->rename($params)) {
                continue;
            }

            if ($this->targetsDegradedDocument($result)) {
                throw new JsonRpcException(JsonRpcError::INVALID_REQUEST, 'Rename is unavailable while an affected open PHP document contains syntax errors.');
            }

            return $result;
        }

        return null;
    }

    /** @param array<array-key, mixed> $edit */
    private function targetsDegradedDocument(array $edit): bool
    {
        $documentChanges = $edit['documentChanges'] ?? null;
        foreach (\is_array($documentChanges) ? $documentChanges : [] as $change) {
            $textDocument = \is_array($change) ? ($change['textDocument'] ?? null) : null;
            $uri = \is_array($textDocument) ? ($textDocument['uri'] ?? null) : null;
            if (\is_string($uri) && $this->overlayHealth->isDegraded($uri)) {
                return true;
            }
        }

        return false;
    }
}
