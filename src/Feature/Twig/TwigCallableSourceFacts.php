<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\SourceFactsInterface;

final class TwigCallableSourceFacts implements SourceFactsInterface
{
    /**
     * Declared with a default so payloads cached before usages existed
     * still unserialize into a valid object.
     *
     * @var list<TwigCallableUsage>
     */
    private array $usages = [];

    /**
     * @param list<TwigCallableDeclaration> $declarations
     * @param list<TwigCallableUsage>       $usages
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $declarations,
        array $usages = [],
    ) {
        $this->usages = $usages;
    }

    /** @return list<TwigCallableUsage> */
    public function usages(): array
    {
        return $this->usages;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<TwigCallableDeclaration> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations && [] === $this->usages;
    }
}
