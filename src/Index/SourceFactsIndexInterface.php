<?php

namespace Symfony\Lsp\Index;

/** @template TFacts of SourceFactsInterface */
interface SourceFactsIndexInterface
{
    /** @param TFacts ...$facts */
    public function replace(SourceFactsInterface ...$facts): void;

    /** @param TFacts $facts */
    public function replaceSource(SourceFactsInterface $facts): void;

    public function removeSource(string $uri): void;

    /** @param TFacts $facts */
    public function overlay(SourceFactsInterface $facts): void;

    public function removeOverlay(string $uri): void;
}
