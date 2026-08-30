<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Index\SourceFactsIndexInterface;
use Symfony\Lsp\Index\SourceFactsInterface;

/** @implements SourceFactsIndexInterface<RouteSourceFacts> */
final class RouteSourceIndexAdapter implements SourceFactsIndexInterface
{
    public function __construct(private readonly RouteDeclarationIndex $declarations, private readonly RouteReferenceIndex $references)
    {
    }

    /** @param RouteSourceFacts ...$facts */
    public function replace(SourceFactsInterface ...$facts): void
    {
        $declarations = [];
        $references = [];
        foreach ($facts as $source) {
            array_push($declarations, ...$source->declarations);
            array_push($references, ...$source->references);
        }
        $this->declarations->replace(...$declarations);
        $this->references->replace(...$references);
    }

    /** @param RouteSourceFacts $facts */
    public function replaceSource(SourceFactsInterface $facts): void
    {
        $this->declarations->replaceSource($facts->uri, ...$facts->declarations);
        $this->references->replaceSource($facts->uri, ...$facts->references);
    }

    public function removeSource(string $uri): void
    {
        $this->declarations->removeSource($uri);
        $this->references->removeSource($uri);
    }

    /** @param RouteSourceFacts $facts */
    public function overlay(SourceFactsInterface $facts): void
    {
        $this->declarations->replaceForUri($facts->uri, ...$facts->declarations);
        $this->references->replaceForUri($facts->uri, ...$facts->references);
    }

    public function removeOverlay(string $uri): void
    {
        $this->declarations->removeOverlay($uri);
        $this->references->removeOverlay($uri);
    }
}
