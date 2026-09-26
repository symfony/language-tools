<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\PositionedRequest;

final class EnvironmentSymbolResolver
{
    public function __construct(
        private readonly PositionedSourceSymbolResolver $positionedSymbols,
        private readonly EnvironmentExtractor $extractor,
    ) {
    }

    /** @return array{EnvironmentReference, Project}|null */
    public function resolve(PositionedRequest $request): ?array
    {
        $document = $request->source;
        $facts = $this->extractor->extract($document);
        $declaration = $this->positionedSymbols->resolve($document, $request->position, $facts->declarations);
        if (null !== $declaration) {
            return [new EnvironmentReference($declaration->name, $request->document->uri, $declaration->range, []), $request->project];
        }
        $reference = $this->positionedSymbols->resolve($document, $request->position, $facts->references);

        return null === $reference ? null : [$reference, $request->project];
    }
}
