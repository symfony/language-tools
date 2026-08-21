<?php

namespace Symfony\Lsp\Index;

/** @template TFacts of SourceFactsInterface */
abstract class AbstractSourceFactsIndex
{
    /** @var SourceFactsStore<TFacts> */
    private readonly SourceFactsStore $facts;

    public function __construct(SourceFactsOverlayOrder $overlayOrder = SourceFactsOverlayOrder::OverlaysLast)
    {
        $this->facts = new SourceFactsStore($overlayOrder);
    }

    /** @param TFacts ...$facts */
    final public function replace(SourceFactsInterface ...$facts): void
    {
        $this->facts->replaceSaved(...$facts);
    }

    /** @param TFacts $facts */
    final public function replaceSource(SourceFactsInterface $facts): void
    {
        $this->facts->replaceSavedFact($facts);
    }

    final public function removeSource(string $uri): void
    {
        $this->facts->removeSaved($uri);
    }

    /** @param TFacts $facts */
    final public function overlay(SourceFactsInterface $facts): void
    {
        $this->facts->replaceOverlay($facts);
    }

    final public function removeOverlay(string $uri): void
    {
        $this->facts->removeOverlay($uri);
    }

    /** @return list<TFacts> */
    final protected function facts(): array
    {
        return $this->facts->effective();
    }
}
