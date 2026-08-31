<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigQuotedArgumentMatcher;

final class TwigAssetReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigCommentParser $commentParser,
        private readonly TwigQuotedArgumentMatcher $matcher,
    ) {
    }

    /** @return list<AssetSourceSymbol> */
    public function extract(string $uri, string $text): array
    {
        $source = $this->commentParser->mask($text);
        $symbols = [];
        foreach ($this->matcher->functionCalls($source, ['asset']) as $call) {
            if (str_starts_with($call->value, '/') || 1 !== preg_match('/^\s*\)/', substr($source, $call->end()))) {
                continue;
            }
            $symbols[] = new AssetSourceSymbol(AssetSymbolKind::Asset, $call->value, $uri, $call->range, false);
        }
        preg_match_all('/\bimportmap\s*\(\s*(\[[^\]]*\]|["\'][^"\']+["\'])\s*\)/s', $source, $calls, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($calls as $call) {
            preg_match_all('/["\']([A-Za-z0-9_@.\/-]+)["\']/', $call[1][0], $entries, \PREG_OFFSET_CAPTURE);
            foreach ($entries[1] as [$name, $offset]) {
                $symbols[] = new AssetSourceSymbol(
                    AssetSymbolKind::Entrypoint,
                    $name,
                    $uri,
                    $this->converter->toRange($text, $call[1][1] + $offset, \strlen($name)),
                    false,
                );
            }
        }

        return $this->unique($symbols);
    }

    /**
     * @param list<AssetSourceSymbol> $symbols
     *
     * @return list<AssetSourceSymbol>
     */
    private function unique(array $symbols): array
    {
        $unique = [];
        foreach ($symbols as $symbol) {
            $key = $symbol->kind->value.'|'.$symbol->range->start->line.'|'.$symbol->range->start->character;
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
