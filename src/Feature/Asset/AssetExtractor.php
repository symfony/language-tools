<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class AssetExtractor
{
    public function __construct(private readonly PositionConverter $converter)
    {
    }

    public function extract(string $uri, string $languageId, string $text): AssetSourceFacts
    {
        $symbols = match ($languageId) {
            'twig' => $this->twigSymbols($uri, $text),
            'php' => str_ends_with((string) parse_url($uri, \PHP_URL_PATH), '/importmap.php') ? $this->importMapSymbols($uri, $text) : [],
            default => [],
        };

        return new AssetSourceFacts($uri, $this->unique($symbols));
    }

    public function completionContext(string $languageId, string $text, int $offset): ?AssetCompletionContext
    {
        if ('twig' !== $languageId) {
            return null;
        }
        $before = substr($text, 0, $offset);
        if (preg_match('/\basset\s*\(\s*["\']([A-Za-z0-9_@.\/-]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(AssetSymbolKind::Asset, $match[1][0], $text, $match[1][1]);
        }
        if (preg_match('/\bimportmap\s*\(\s*(?:\[[^\]]*)?["\']([A-Za-z0-9_@.\/-]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(AssetSymbolKind::Entrypoint, $match[1][0], $text, $match[1][1]);
        }

        return null;
    }

    /** @return list<AssetSourceSymbol> */
    private function twigSymbols(string $uri, string $text): array
    {
        $symbols = [];
        preg_match_all('/\basset\s*\(\s*(["\'])([^"\']+)\1\s*\)/', $text, $assets, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($assets as $asset) {
            if (str_starts_with($asset[2][0], '/')) {
                continue;
            }
            $symbols[] = $this->symbol(AssetSymbolKind::Asset, $asset[2][0], $uri, $text, $asset[2][1], false);
        }
        preg_match_all('/\bimportmap\s*\(\s*(\[[^\]]*\]|["\'][^"\']+["\'])\s*\)/s', $text, $calls, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($calls as $call) {
            preg_match_all('/["\']([A-Za-z0-9_@.\/-]+)["\']/', $call[1][0], $entries, \PREG_OFFSET_CAPTURE);
            foreach ($entries[1] as [$name, $offset]) {
                $symbols[] = $this->symbol(AssetSymbolKind::Entrypoint, $name, $uri, $text, $call[1][1] + $offset, false);
            }
        }

        return $symbols;
    }

    /** @return list<AssetSourceSymbol> */
    private function importMapSymbols(string $uri, string $text): array
    {
        if (!preg_match('/\breturn\s*\[/', $text, $return, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $open = $return[0][1] + \strlen($return[0][0]) - 1;
        $close = $this->matchingBracket($text, $open);
        if (null === $close) {
            $close = \strlen($text);
        }
        $symbols = [];
        $depth = 0;
        for ($offset = $open; $offset < $close; ++$offset) {
            $character = $text[$offset];
            if ('[' === $character) {
                ++$depth;
                continue;
            }
            if (']' === $character) {
                --$depth;
                continue;
            }
            if (1 !== $depth || ('"' !== $character && "'" !== $character)) {
                continue;
            }
            $nameStart = $offset + 1;
            $nameEnd = $nameStart;
            while ($nameEnd < $close && $text[$nameEnd] !== $character) {
                $nameEnd += '\\' === $text[$nameEnd] ? 2 : 1;
            }
            if ($nameEnd >= $close) {
                break;
            }
            $afterName = $nameEnd + 1;
            while ($afterName < $close && ctype_space($text[$afterName])) {
                ++$afterName;
            }
            if ('=>' !== substr($text, $afterName, 2)) {
                $offset = $nameEnd;
                continue;
            }
            $optionsOpen = $afterName + 2;
            while ($optionsOpen < $close && ctype_space($text[$optionsOpen])) {
                ++$optionsOpen;
            }
            if ('[' !== ($text[$optionsOpen] ?? null)) {
                $offset = $nameEnd;
                continue;
            }
            $optionsClose = $this->matchingBracket($text, $optionsOpen);
            if (null === $optionsClose) {
                break;
            }
            $options = substr($text, $optionsOpen + 1, $optionsClose - $optionsOpen - 1);
            if (preg_match('/["\']entrypoint["\']\s*=>\s*true\b/', $options)) {
                $name = substr($text, $nameStart, $nameEnd - $nameStart);
                $symbols[] = $this->symbol(AssetSymbolKind::Entrypoint, $name, $uri, $text, $nameStart, true);
            }
            $offset = $optionsClose;
        }

        return $symbols;
    }

    private function matchingBracket(string $text, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = \strlen($text);
        for ($offset = $open; $offset < $length; ++$offset) {
            $character = $text[$offset];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;
            } elseif ('[' === $character) {
                ++$depth;
            } elseif (']' === $character && 0 === --$depth) {
                return $offset;
            }
        }

        return null;
    }

    private function context(AssetSymbolKind $kind, string $prefix, string $text, int $offset): AssetCompletionContext
    {
        return new AssetCompletionContext($kind, $prefix, $this->offsetRange($text, $offset, \strlen($prefix)));
    }

    private function symbol(AssetSymbolKind $kind, string $name, string $uri, string $text, int $offset, bool $declaration): AssetSourceSymbol
    {
        return new AssetSourceSymbol($kind, $name, $uri, $this->offsetRange($text, $offset, \strlen($name)), $declaration);
    }

    private function offsetRange(string $text, int $offset, int $length): Range
    {
        return new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + $length));
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
            $key = $symbol->kind()->value.'|'.$symbol->range()->start()->line().'|'.$symbol->range()->start()->character();
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
