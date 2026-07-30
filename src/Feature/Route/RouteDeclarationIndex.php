<?php

namespace Symfony\Lsp\Feature\Route;

final class RouteDeclarationIndex
{
    /** @var array<string, list<RouteDeclaration>> */
    private array $declarations = [];

    public function replace(RouteDeclaration ...$declarations): void
    {
        $this->declarations = [];
        foreach ($declarations as $declaration) {
            $this->declarations[$declaration->name()][] = $declaration;
        }
    }

    public function replaceForUri(string $uri, RouteDeclaration ...$declarations): void
    {
        $remaining = [];
        foreach ($this->declarations as $indexedDeclarations) {
            foreach ($indexedDeclarations as $declaration) {
                if ($declaration->uri() !== $uri) {
                    $remaining[] = $declaration;
                }
            }
        }

        $this->replace(...$remaining, ...$declarations);
    }

    /**
     * @return list<RouteDeclaration>
     */
    public function find(string $name): array
    {
        return $this->declarations[$name] ?? [];
    }
}
