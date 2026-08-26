<?php

namespace Symfony\Lsp\Index;

/** @template TFacts of SourceFactsInterface */
final class SourceFactsStore
{
    /** @var array<string, TFacts> */
    private array $saved = [];

    /** @var array<string, TFacts> */
    private array $overlays = [];

    /** @var list<TFacts>|null */
    private ?array $effective = null;

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
        $this->effective = null;
    }

    /** @param TFacts $facts */
    public function replaceSavedFact(SourceFactsInterface $facts): void
    {
        $this->saved[$facts->uri()] = $facts;
        $this->effective = null;
    }

    public function removeSaved(string $uri): void
    {
        unset($this->saved[$uri]);
        $this->effective = null;
    }

    /** @param TFacts $facts */
    public function replaceOverlay(SourceFactsInterface $facts): void
    {
        $this->overlays[$facts->uri()] = $facts;
        $this->effective = null;
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
        $this->effective = null;
    }

    /** @return list<TFacts> */
    public function effective(): array
    {
        return $this->effective ??= SourceFactsOverlayOrder::PreserveSavedPosition === $this->overlayOrder
            ? array_values(array_replace($this->saved, $this->overlays))
            : [...array_values(array_diff_key($this->saved, $this->overlays)), ...array_values($this->overlays)];
    }
}
