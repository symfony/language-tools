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
    private bool $stale = true;
    private bool $scanned = false;

    public function __construct()
    {
        $this->facts = new SourceFactsStore();
    }

    /** @param TFacts ...$facts */
    final public function replace(SourceFactsInterface ...$facts): void
    {
        if ($this->facts->replaceSaved(...$facts)) {
            $this->stale = true;
        }
        $this->scanned = true;
    }

    /** @param TFacts $facts */
    final public function replaceSource(SourceFactsInterface $facts): void
    {
        if ($this->facts->replaceSavedFact($facts)) {
            $this->stale = true;
        }
    }

    final public function removeSource(string $uri): void
    {
        if ($this->facts->removeSaved($uri)) {
            $this->stale = true;
        }
    }

    /** @param TFacts $facts */
    final public function overlay(SourceFactsInterface $facts): void
    {
        if ($this->facts->replaceOverlay($facts)) {
            $this->stale = true;
        }
    }

    final public function removeOverlay(string $uri): void
    {
        if ($this->facts->removeOverlay($uri)) {
            $this->stale = true;
        }
    }

    final public function hasScannedSources(): bool
    {
        return $this->scanned;
    }

    /** @return TFacts|null */
    final public function factsForUri(string $uri): ?SourceFactsInterface
    {
        return $this->facts->forUri($uri);
    }

    /** Rebuilds the derived state built by build() when facts or runtime metadata changed. */
    final protected function derive(): void
    {
        if (!$this->stale) {
            return;
        }

        // cleared first so that accessors called from build() do not rebuild again
        $this->stale = false;
        $this->build();
    }

    /** Marks the derived state stale after a runtime metadata replacement. */
    final protected function invalidate(): void
    {
        $this->stale = true;
    }

    /** @return list<TFacts> */
    final protected function facts(): array
    {
        return $this->facts->effective();
    }

    abstract protected function build(): void;
}
