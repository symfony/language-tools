<?php

namespace Symfony\Lsp\Feature\Twig;

final class TemplateSourceFacts
{
    /** @param list<TemplateReference> $references */
    public function __construct(
        private readonly string $uri,
        private readonly ?TemplateDeclaration $declaration,
        private readonly array $references,
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function declaration(): ?TemplateDeclaration
    {
        return $this->declaration;
    }

    /** @return list<TemplateReference> */
    public function references(): array
    {
        return $this->references;
    }
}
