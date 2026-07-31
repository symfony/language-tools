<?php

namespace Symfony\Lsp\Feature\Route;

final class RouteDeclarationIndex
{
    /** @var list<RouteDeclaration> */
    private array $declarations = [];

    /** @var array<string, list<RouteDeclaration>> */
    private array $overlays = [];

    public function replace(RouteDeclaration ...$declarations): void
    {
        $this->declarations = array_values($declarations);
    }

    public function replaceSource(string $uri, RouteDeclaration ...$declarations): void
    {
        $this->declarations = array_values(array_filter(
            $this->declarations,
            static fn (RouteDeclaration $declaration): bool => $declaration->uri() !== $uri,
        ));
        array_push($this->declarations, ...$declarations);
    }

    public function removeSource(string $uri): void
    {
        $this->replaceSource($uri);
    }

    public function replaceForUri(string $uri, RouteDeclaration ...$declarations): void
    {
        $this->overlays[$uri] = array_values($declarations);
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    /**
     * @return list<RouteDeclaration>
     */
    public function find(string $name): array
    {
        $declarations = [];
        foreach ($this->declarations as $declaration) {
            if ($declaration->name() === $name && !isset($this->overlays[$declaration->uri()])) {
                $declarations[] = $declaration;
            }
        }
        foreach ($this->overlays as $overlayDeclarations) {
            foreach ($overlayDeclarations as $declaration) {
                if ($declaration->name() === $name) {
                    $declarations[] = $declaration;
                }
            }
        }

        return $declarations;
    }
}
