<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\SourceFactsIndexInterface;
use Symfony\Lsp\Index\SourceFactsInterface;

/** @implements SourceFactsIndexInterface<TemplateSourceFacts> */
final class TemplateSourceIndexAdapter implements SourceFactsIndexInterface
{
    public function __construct(private readonly TemplateIndex $index)
    {
    }

    /** @param TemplateSourceFacts ...$facts */
    public function replace(SourceFactsInterface ...$facts): void
    {
        $declarations = [];
        $references = [];
        foreach ($facts as $source) {
            if (null !== $source->declaration()) {
                $declarations[] = $source->declaration();
            }
            array_push($references, ...$source->references());
        }
        $this->index->replaceSources(...$declarations);
        $this->index->replaceReferences(...$references);
    }

    /** @param TemplateSourceFacts $facts */
    public function replaceSource(SourceFactsInterface $facts): void
    {
        $this->index->replaceSource($facts->uri(), $facts->declaration(), $facts->references());
    }

    public function removeSource(string $uri): void
    {
        $this->index->removeSource($uri);
    }

    /** @param TemplateSourceFacts $facts */
    public function overlay(SourceFactsInterface $facts): void
    {
        $this->index->overlay($facts->uri(), $facts->declaration(), $facts->references());
    }

    public function removeOverlay(string $uri): void
    {
        $this->index->removeOverlay($uri);
    }
}
