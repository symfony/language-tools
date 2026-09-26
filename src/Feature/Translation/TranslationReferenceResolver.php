<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Protocol\PositionedRequest;

final readonly class TranslationReferenceResolver
{
    public function __construct(
        private PositionConverter $positions,
        private TranslationExtractor $extractor,
    ) {
    }

    public function resolve(PositionedRequest $request): ?ResolvedTranslationReference
    {
        $text = $request->document->text;
        $offset = $request->offset;
        $facts = $this->extractor->extract($request->source);
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
