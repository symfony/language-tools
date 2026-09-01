<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

final class SecuritySymbolResolver
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
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
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        foreach ($this->extractor->extract(new SourceDocument($request->document->uri, $request->document->languageId, $request->document->text))->symbols as $symbol) {
            if ($this->converter->containsByteOffset($request->document->text, $symbol->range, $offset, inclusiveEnd: true)) {
                return [$symbol, $request->project];
            }
        }

        return null;
    }
}
