<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class DependencyInjectionSymbolResolver
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly YamlDependencyInjectionExtractor $yamlExtractor,
        private readonly PhpAutowireReferenceExtractor $autowireExtractor,
    ) {
    }

    public function resolve(string $uri, string $languageId, string $text, Position $position): ?DependencyInjectionSymbol
    {
        $offset = $this->positionConverter->toByteOffset($text, $position);
        if ('yaml' === $languageId) {
            $facts = $this->yamlExtractor->extract($uri, $text);
            foreach ($facts->services() as $declaration) {
                if ($this->contains($text, $declaration->range(), $offset)) {
                    return new DependencyInjectionSymbol(
                        DependencyInjectionSymbolKind::Service,
                        $declaration->id(),
                        $declaration->range(),
                    );
                }
            }
            foreach ($facts->parameters() as $declaration) {
                if ($this->contains($text, $declaration->range(), $offset)) {
                    return new DependencyInjectionSymbol(
                        DependencyInjectionSymbolKind::Parameter,
                        $declaration->name(),
                        $declaration->range(),
                    );
                }
            }
            $references = $facts->references();
        } elseif ('php' === $languageId) {
            $references = $this->autowireExtractor->extract($uri, $text);
        } else {
            return null;
        }

        foreach ($references as $reference) {
            if ($this->contains($text, $reference->range(), $offset)) {
                return new DependencyInjectionSymbol(
                    $reference->kind(),
                    $reference->name(),
                    $reference->range(),
                );
            }
        }

        return null;
    }

    private function contains(string $text, Range $range, int $offset): bool
    {
        return $offset >= $this->positionConverter->toByteOffset($text, $range->start())
            && $offset <= $this->positionConverter->toByteOffset($text, $range->end());
    }
}
