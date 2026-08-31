<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;

final class DependencyInjectionSymbolResolver
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly DependencyInjectionDocumentExtractor $extractor,
    ) {
    }

    public function resolve(string $uri, string $languageId, string $text, Position $position): ?DependencyInjectionSymbol
    {
        $facts = $this->extractor->extractForInteractive($uri, $languageId, $text);
        if (null === $facts) {
            return null;
        }

        $offset = $this->positionConverter->toByteOffset($text, $position);
        foreach ($facts->services as $declaration) {
            if ($this->positionConverter->containsByteOffset($text, $declaration->range, $offset, inclusiveEnd: true)) {
                return new DependencyInjectionSymbol(
                    DependencyInjectionSymbolKind::Service,
                    $declaration->id,
                    $declaration->range,
                );
            }
        }
        foreach ($facts->parameters as $declaration) {
            if ($this->positionConverter->containsByteOffset($text, $declaration->range, $offset, inclusiveEnd: true)) {
                return new DependencyInjectionSymbol(
                    DependencyInjectionSymbolKind::Parameter,
                    $declaration->name,
                    $declaration->range,
                );
            }
        }

        foreach ($facts->references as $reference) {
            if ($this->positionConverter->containsByteOffset($text, $reference->range, $offset, inclusiveEnd: true)) {
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
