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

    /**
     * @return list<RouteDeclaration>
     */
    public function find(string $name): array
    {
        return $this->declarations[$name] ?? [];
    }
}
