<?php

namespace Symfony\Lsp\Index;

/** @template TFacts of SourceFactsInterface */
final class SourceFactsStore
{
    /** @var array<string, TFacts> */
    private array $saved = [];

    /** @var array<string, TFacts> */
    private array $overlays = [];

    public function __construct(private readonly SourceFactsOverlayOrder $overlayOrder = SourceFactsOverlayOrder::OverlaysLast)
    {
    }

    /** @param TFacts ...$facts */
    public function replaceSaved(SourceFactsInterface ...$facts): void
    {
        $this->saved = [];
        foreach ($facts as $item) {
            $this->saved[$item->uri()] = $item;
        }
    }

    /** @param TFacts $facts */
    public function replaceSavedFact(SourceFactsInterface $facts): void
    {
        $this->saved[$facts->uri()] = $facts;
    }

    public function removeSaved(string $uri): void
    {
        unset($this->saved[$uri]);
    }

    /** @param TFacts $facts */
    public function replaceOverlay(SourceFactsInterface $facts): void
    {
        $this->overlays[$facts->uri()] = $facts;
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    /** @return list<TFacts> */
    public function effective(): array
    {
        return SourceFactsOverlayOrder::PreserveSavedPosition === $this->overlayOrder
            ? array_values(array_replace($this->saved, $this->overlays))
            : [...array_values(array_diff_key($this->saved, $this->overlays)), ...array_values($this->overlays)];
    }
}
