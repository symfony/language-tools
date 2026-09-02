<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Index\SourceDocument;

final readonly class TranslationReferenceResolver
{
    public function __construct(
        private DocumentContextResolver $documents,
        private PositionConverter $positions,
        private TranslationExtractor $extractor,
    ) {
    }

    /** @param array<array-key, mixed> $params */
    public function resolve(array $params): ?ResolvedTranslationReference
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request) {
            return null;
        }

        $text = $request->document->text;
        $offset = $this->positions->toByteOffset($text, $request->position);
        $facts = $this->extractor->extract(SourceDocument::fromDocument($request->document));
        foreach ($facts->declarations as $declaration) {
            if ($this->positions->containsByteOffset($text, $declaration->range, $offset, inclusiveEnd: true)) {
                return new ResolvedTranslationReference(
                    new TranslationReference(
                        $declaration->key,
                        $declaration->domain,
                        $request->document->uri,
                        $declaration->range,
                    ),
                    $request->project,
                );
            }
        }
        foreach ($facts->references as $reference) {
            if ($this->positions->containsByteOffset($text, $reference->range, $offset, inclusiveEnd: true)) {
                return new ResolvedTranslationReference($reference, $request->project);
            }
        }

        return null;
    }
}
