<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\SourceFactsInterface;

final class TemplateSourceFacts implements SourceFactsInterface
{
    /** @param list<TemplateReference> $references */
    public function __construct(
        public readonly string $uri,
        public readonly ?TemplateDeclaration $declaration,
        public readonly array $references,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->declaration && [] === $this->references;
    }
}
