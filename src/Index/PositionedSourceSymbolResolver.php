<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\Position;
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
    public function resolve(SourceDocument $document, Position $position, iterable $symbols): ?RangedSourceSymbolInterface
    {
        $offset = $this->positions->toByteOffset($document->text, $position);
        foreach ($symbols as $symbol) {
            if ($this->positions->containsByteOffset($document->text, $symbol->range, $offset, inclusiveEnd: true)) {
                return $symbol;
            }
        }

        return null;
    }
}
