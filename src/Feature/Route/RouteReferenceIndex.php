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

    /**
     * @return list<RouteReferenceLocation>
     */
    public function find(string $name): array
    {
        return $this->references[$name] ?? [];
    }
}
