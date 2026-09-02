<?php

namespace Symfony\Lsp\Feature;

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

            return $this->targetsDegradedDocument($result) ? null : $result;
        }

        return null;
    }

    /** @param array<array-key, mixed> $edit */
    private function targetsDegradedDocument(array $edit): bool
    {
        $changes = $edit['changes'] ?? null;
        if (\is_array($changes)) {
            foreach (array_keys($changes) as $uri) {
                if (\is_string($uri) && $this->overlayHealth->isDegraded($uri)) {
                    return true;
                }
            }
        }

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
