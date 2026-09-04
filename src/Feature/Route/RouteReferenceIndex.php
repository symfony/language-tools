<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;

final class RouteReferenceIndex
{
    /** @var array<string, list<RouteReferenceLocation>> */
    private array $references = [];

    /** @var array<string, list<RouteReferenceLocation>> */
    private array $overlays = [];

    public function __construct(
        private readonly DependencyInjectionSourceIndex $classIndex,
    ) {
    }

    public function replace(RouteReferenceLocation ...$references): void
    {
        $this->references = [];
        foreach ($references as $reference) {
            $this->references[$reference->uri][] = $reference;
        }
    }

    public function replaceSource(string $uri, RouteReferenceLocation ...$references): void
    {
        unset($this->references[$uri]);
        if ([] !== $references) {
            $this->references[$uri] = array_values($references);
        }
    }

    public function removeSource(string $uri): void
    {
        unset($this->references[$uri]);
    }

    public function replaceForUri(string $uri, RouteReferenceLocation ...$references): void
    {
        $this->overlays[$uri] = array_values($references);
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    /** @return list<RouteReferenceLocation> */
    public function forUri(string $uri): array
    {
        $references = \array_key_exists($uri, $this->overlays)
            ? $this->overlays[$uri]
            : $this->references[$uri] ?? [];

        return array_values(array_filter($references, $this->isSupported(...)));
    }

    /**
     * @return list<RouteReferenceLocation>
     */
    public function find(string $name): array
    {
        $references = [];
        foreach ($this->references as $uri => $sourceReferences) {
            if (isset($this->overlays[$uri])) {
                continue;
            }
            foreach ($sourceReferences as $reference) {
                if ($reference->name === $name && $this->isSupported($reference)) {
                    $references[] = $reference;
                }
            }
        }
        foreach ($this->overlays as $overlayReferences) {
            foreach ($overlayReferences as $reference) {
                if ($reference->name === $name && $this->isSupported($reference)) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    private function isSupported(RouteReferenceLocation $reference): bool
    {
        return null === $reference->controllerClass
            || $this->classIndex->isSubclassOf(
                $reference->controllerClass,
                'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController',
            );
    }
}
