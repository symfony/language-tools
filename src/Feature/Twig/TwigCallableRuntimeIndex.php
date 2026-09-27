<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableRuntimeIndex
{
    /** @var array<string, array<string, list<TwigCallableRuntimeDeclaration>>> */
    private array $declarations = [];

    public function replace(TwigCallableRuntimeDeclaration ...$declarations): void
    {
        $this->declarations = [];
        foreach ($declarations as $declaration) {
            $this->declarations[$declaration->kind->value][$declaration->name][] = $declaration;
        }
    }

    /** @return list<TwigCallableRuntimeDeclaration> */
    public function declarations(TwigCallableKind $kind, string $name): array
    {
        return $this->declarations[$kind->value][$name] ?? [];
    }
}
