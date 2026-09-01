<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

final class EnvironmentSymbolResolver
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionedSourceSymbolResolver $positionedSymbols,
        private readonly EnvironmentExtractor $extractor,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{EnvironmentReference, Project}|null
     */
    public function resolve(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $document = new SourceDocument($request->document->uri, $request->document->languageId, $request->document->text);
        $facts = $this->extractor->extract($document);
        $declaration = $this->positionedSymbols->resolve($document, $request->position, $facts->declarations);
        if (null !== $declaration) {
            return [new EnvironmentReference($declaration->name, $request->document->uri, $declaration->range, []), $request->project];
        }
        $reference = $this->positionedSymbols->resolve($document, $request->position, $facts->references);

        return null === $reference ? null : [$reference, $request->project];
    }
}
