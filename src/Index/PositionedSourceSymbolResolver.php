<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\PositionConverter;

final class PositionedSourceSymbolResolver
{
    public function __construct(private readonly PositionConverter $positions)
    {
    }

    /**
     * @template T of RangedSourceSymbolInterface
     *
     * @param iterable<T> $symbols
     *
     * @return T|null
     */
    public function resolve(SourceDocument $document, int $offset, iterable $symbols): ?RangedSourceSymbolInterface
    {
        foreach ($symbols as $symbol) {
            if ($this->positions->containsByteOffset($document->text, $symbol->range, $offset, inclusiveEnd: true)) {
                return $symbol;
            }
        }

        return null;
    }
}
