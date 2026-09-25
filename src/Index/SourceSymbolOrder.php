<?php

namespace Symfony\Lsp\Index;

final class SourceSymbolOrder
{
    public static function byLocation(LocatedSourceSymbolInterface $left, LocatedSourceSymbolInterface $right): int
    {
        return [$left->uri, $left->range->start->line, $left->range->start->character]
            <=> [$right->uri, $right->range->start->line, $right->range->start->character];
    }
}
