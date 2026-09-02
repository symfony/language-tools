<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

final class SecuritySymbolResolver
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionedSourceSymbolResolver $positionedSymbols,
        private readonly SecurityExtractor $extractor,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{SecuritySourceSymbol, Project}|null
     */
    public function resolve(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $document = SourceDocument::fromDocument($request->document);
        $symbol = $this->positionedSymbols->resolve($document, $request->position, $this->extractor->extract($document)->symbols);

        return null === $symbol ? null : [$symbol, $request->project];
    }
}
