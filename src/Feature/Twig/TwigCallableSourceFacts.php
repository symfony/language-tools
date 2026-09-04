<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\SourceFactsInterface;

final class TwigCallableSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<TwigCallableDeclaration>   $declarations
     * @param list<TwigCallableUsage>         $usages
     * @param list<TwigCallableCallReference> $calls
     * @param list<TwigCallableSourceMethod>  $methods
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $declarations,
        public readonly array $usages = [],
        public readonly array $calls = [],
        public readonly array $methods = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations && [] === $this->usages && [] === $this->calls && [] === $this->methods;
    }
}
