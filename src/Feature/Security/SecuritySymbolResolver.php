<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\PositionedRequest;

final class SecuritySymbolResolver
{
    public function __construct(
        private readonly PositionedSourceSymbolResolver $positionedSymbols,
        private readonly SecurityExtractor $extractor,
    ) {
    }

    /** @return array{SecuritySourceSymbol, Project}|null */
    public function resolve(PositionedRequest $request): ?array
    {
        $document = $request->source;
        $symbol = $this->positionedSymbols->resolve($document, $request->offset, $this->extractor->extract($document)->symbols);

        return null === $symbol ? null : [$symbol, $request->project];
    }
}
