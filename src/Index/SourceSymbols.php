<?php

namespace Symfony\Lsp\Index;

final class SourceSymbols
{
    /**
     * Keeps the last symbol of every location and discriminator, in first-seen order.
     *
     * @template TSymbol of LocatedSourceSymbolInterface
     *
     * @param list<TSymbol>                    $symbols
     * @param (callable(TSymbol): string)|null $discriminator
     *
     * @return list<TSymbol>
     */
    public static function unique(array $symbols, ?callable $discriminator = null): array
    {
        $unique = [];
        foreach ($symbols as $symbol) {
            $range = $symbol->range;
            $key = implode("\0", [
                null === $discriminator ? '' : $discriminator($symbol),
                $symbol->uri,
                $range->start->line,
                $range->start->character,
                $range->end->line,
                $range->end->character,
            ]);
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
