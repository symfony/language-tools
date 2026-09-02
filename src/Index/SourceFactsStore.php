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
    public function replaceSaved(SourceFactsInterface ...$facts): bool
    {
        $saved = [];
        foreach ($facts as $item) {
            $saved[$item->uri] = $item;
        }
        if ($this->sameFacts($this->saved, $saved)) {
            return false;
        }

        $this->saved = $saved;
        $this->effective = null;

        return true;
    }

    /** @param TFacts $facts */
    public function replaceSavedFact(SourceFactsInterface $facts): bool
    {
        $uri = $facts->uri;
        if (isset($this->saved[$uri]) && $this->saved[$uri] == $facts) {
            return false;
        }

        $this->saved[$uri] = $facts;
        $this->effective = null;

        return true;
    }

    public function removeSaved(string $uri): bool
    {
        if (!isset($this->saved[$uri])) {
            return false;
        }

        unset($this->saved[$uri]);
        $this->effective = null;

        return true;
    }

    /** @param TFacts $facts */
    public function replaceOverlay(SourceFactsInterface $facts): bool
    {
        $uri = $facts->uri;
        if (isset($this->overlays[$uri]) && $this->overlays[$uri] == $facts) {
            return false;
        }

        $this->overlays[$uri] = $facts;
        $this->effective = null;

        return true;
    }

    public function removeOverlay(string $uri): bool
    {
        if (!isset($this->overlays[$uri])) {
            return false;
        }

        unset($this->overlays[$uri]);
        $this->effective = null;

        return true;
    }

    /** @return list<TFacts> */
    public function effective(): array
    {
        return $this->effective ??= SourceFactsOverlayOrder::PreserveSavedPosition === $this->overlayOrder
            ? array_values(array_replace($this->saved, $this->overlays))
            : [...array_values(array_diff_key($this->saved, $this->overlays)), ...array_values($this->overlays)];
    }

    /**
     * @param array<string, TFacts> $left
     * @param array<string, TFacts> $right
     */
    private function sameFacts(array $left, array $right): bool
    {
        if (array_keys($left) !== array_keys($right)) {
            return false;
        }
        foreach ($left as $uri => $facts) {
            if ($facts != $right[$uri]) {
                return false;
            }
        }

        return true;
    }
}
