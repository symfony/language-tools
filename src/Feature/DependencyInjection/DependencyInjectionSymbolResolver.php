<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Index\SourceDocument;

final class DependencyInjectionSymbolResolver
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly DependencyInjectionDocumentExtractor $extractor,
    ) {
    }

    public function resolve(SourceDocument $document, Position $position): ?DependencyInjectionSymbol
    {
        $facts = $this->extractor->extractForInteractive($document);
        if (null === $facts) {
            return null;
        }

        $offset = $this->positionConverter->toByteOffset($document->text, $position);
        foreach ($facts->services as $declaration) {
            if ($this->positionConverter->containsByteOffset($document->text, $declaration->range, $offset, inclusiveEnd: true)) {
                return new DependencyInjectionSymbol(
                    DependencyInjectionSymbolKind::Service,
                    $declaration->id,
                    $declaration->range,
                );
            }
        }
        foreach ($facts->parameters as $declaration) {
            if ($this->positionConverter->containsByteOffset($document->text, $declaration->range, $offset, inclusiveEnd: true)) {
                return new DependencyInjectionSymbol(
                    DependencyInjectionSymbolKind::Parameter,
                    $declaration->name,
                    $declaration->range,
                );
            }
        }

        foreach ($facts->references as $reference) {
            if ($this->positionConverter->containsByteOffset($document->text, $reference->range, $offset, inclusiveEnd: true)) {
                return new DependencyInjectionSymbol(
                    $reference->kind,
                    $reference->name,
                    $reference->range,
                );
            }
        }

        return null;
    }
}
