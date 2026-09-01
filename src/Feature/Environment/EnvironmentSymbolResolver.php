<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

final class EnvironmentSymbolResolver
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
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
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $facts = $this->extractor->extract(new SourceDocument($request->document->uri, $request->document->languageId, $request->document->text));
        foreach ($facts->declarations as $declaration) {
            if ($this->converter->containsByteOffset($request->document->text, $declaration->range, $offset, inclusiveEnd: true)) {
                return [new EnvironmentReference($declaration->name, $request->document->uri, $declaration->range, []), $request->project];
            }
        }
        foreach ($facts->references as $reference) {
            if ($this->converter->containsByteOffset($request->document->text, $reference->range, $offset, inclusiveEnd: true)) {
                return [$reference, $request->project];
            }
        }

        return null;
    }
}
