<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;

final class RouteReferenceIndex
{
    /** @var list<RouteReferenceLocation> */
    private array $references = [];

    /** @var array<string, list<RouteReferenceLocation>> */
    private array $overlays = [];

    public function __construct(
        private readonly DependencyInjectionSourceIndex $classIndex,
    ) {
    }

    public function replace(RouteReferenceLocation ...$references): void
    {
        $this->references = array_values($references);
    }

    public function replaceSource(string $uri, RouteReferenceLocation ...$references): void
    {
        $this->references = array_values(array_filter(
            $this->references,
            static fn (RouteReferenceLocation $reference): bool => $reference->uri() !== $uri,
        ));
        array_push($this->references, ...$references);
    }

    public function removeSource(string $uri): void
    {
        $this->replaceSource($uri);
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
            if ($reference->name() === $name && !isset($this->overlays[$reference->uri()]) && $this->isSupported($reference)) {
                $references[] = $reference;
            }
        }
        foreach ($this->overlays as $overlayReferences) {
            foreach ($overlayReferences as $reference) {
                if ($reference->name() === $name && $this->isSupported($reference)) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    private function isSupported(RouteReferenceLocation $reference): bool
    {
        return null === $reference->controllerClass()
            || $this->classIndex->isSubclassOf(
                $reference->controllerClass(),
                'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController',
            );
    }
}
