<?php

namespace Symfony\Lsp\Feature\Route;

final class RouteReferenceIndex
{
    /** @var list<RouteReferenceLocation> */
    private array $references = [];

    /** @var array<string, list<RouteReferenceLocation>> */
    private array $overlays = [];

    public function replace(RouteReferenceLocation ...$references): void
    {
        $this->references = array_values($references);
    }

    public function replaceForUri(string $uri, RouteReferenceLocation ...$references): void
    {
        $this->overlays[$uri] = array_values($references);
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    /**
     * @return list<RouteReferenceLocation>
     */
    public function find(string $name): array
    {
        $references = [];
        foreach ($this->references as $reference) {
            if ($reference->name() === $name && !isset($this->overlays[$reference->uri()])) {
                $references[] = $reference;
            }
        }
        foreach ($this->overlays as $overlayReferences) {
            foreach ($overlayReferences as $reference) {
                if ($reference->name() === $name) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }
}
