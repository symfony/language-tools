<?php

namespace Symfony\Lsp\Index;

/**
 * @template TFacts of SourceFactsInterface
 *
 * @implements SourceFactsIndexInterface<TFacts>
 */
abstract class AbstractSourceFactsIndex implements SourceFactsIndexInterface
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
        if ($this->facts->replaceSaved(...$facts)) {
            $this->factsChanged();
        }
        $this->factsReplaced();
    }

    /** @param TFacts $facts */
    final public function replaceSource(SourceFactsInterface $facts): void
    {
        if ($this->facts->replaceSavedFact($facts)) {
            $this->factsChanged();
        }
    }

    final public function removeSource(string $uri): void
    {
        if ($this->facts->removeSaved($uri)) {
            $this->factsChanged();
        }
    }

    /** @param TFacts $facts */
    final public function overlay(SourceFactsInterface $facts): void
    {
        if ($this->facts->replaceOverlay($facts)) {
            $this->factsChanged();
        }
    }

    final public function removeOverlay(string $uri): void
    {
        if ($this->facts->removeOverlay($uri)) {
            $this->factsChanged();
        }
    }

    protected function factsReplaced(): void
    {
    }

    protected function factsChanged(): void
    {
    }

    /** @return list<TFacts> */
    final protected function facts(): array
    {
        return $this->facts->effective();
    }
}
