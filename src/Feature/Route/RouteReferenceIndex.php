<?php

namespace Symfony\Lsp\Feature\Route;

final class RouteReferenceIndex
{
    /** @var array<string, list<RouteReferenceLocation>> */
    private array $references = [];

    public function replace(RouteReferenceLocation ...$references): void
    {
        $this->references = [];
        foreach ($references as $reference) {
            $this->references[$reference->name()][] = $reference;
        }
    }

    public function replaceForUri(string $uri, RouteReferenceLocation ...$references): void
    {
        $remaining = [];
        foreach ($this->references as $indexedReferences) {
            foreach ($indexedReferences as $reference) {
                if ($reference->uri() !== $uri) {
                    $remaining[] = $reference;
                }
            }
        }

        $this->replace(...$remaining, ...$references);
    }

    /**
     * @return list<RouteReferenceLocation>
     */
    public function find(string $name): array
    {
        return $this->references[$name] ?? [];
    }
}
