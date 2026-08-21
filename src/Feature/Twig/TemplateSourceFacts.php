<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\SourceFactsInterface;

final class TemplateSourceFacts implements SourceFactsInterface
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

    public function isEmpty(): bool
    {
        return null === $this->declaration && [] === $this->references;
    }
}
