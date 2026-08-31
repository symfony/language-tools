<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;

final class ImportMapEntrypointExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpCommentParserInterface $commentParser,
    ) {
    }

    /** @return list<AssetSourceSymbol> */
    public function extract(string $uri, string $text): array
    {
        $source = $this->commentParser->mask($text);
        if (!preg_match('/\breturn\s*\[/', $source, $return, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $open = $return[0][1] + \strlen($return[0][0]) - 1;
        $close = $this->matchingBracket($source, $open);
        if (null === $close) {
            $close = \strlen($source);
        }
        $symbols = [];
        $depth = 0;
        for ($offset = $open; $offset < $close; ++$offset) {
            $character = $source[$offset];
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
            while ($nameEnd < $close && $source[$nameEnd] !== $character) {
                $nameEnd += '\\' === $source[$nameEnd] ? 2 : 1;
            }
            if ($nameEnd >= $close) {
                break;
            }
            $afterName = $nameEnd + 1;
            while ($afterName < $close && ctype_space($source[$afterName])) {
                ++$afterName;
            }
            if ('=>' !== substr($source, $afterName, 2)) {
                $offset = $nameEnd;
                continue;
            }
            $optionsOpen = $afterName + 2;
            while ($optionsOpen < $close && ctype_space($source[$optionsOpen])) {
                ++$optionsOpen;
            }
            if ('[' !== ($source[$optionsOpen] ?? null)) {
                $offset = $nameEnd;
                continue;
            }
            $optionsClose = $this->matchingBracket($source, $optionsOpen);
            if (null === $optionsClose) {
                break;
            }
            $options = substr($source, $optionsOpen + 1, $optionsClose - $optionsOpen - 1);
            if (preg_match('/["\']entrypoint["\']\s*=>\s*true\b/', $options)) {
                $name = substr($source, $nameStart, $nameEnd - $nameStart);
                $symbols[] = new AssetSourceSymbol(
                    AssetSymbolKind::Entrypoint,
                    $name,
                    $uri,
                    $this->converter->toRange($text, $nameStart, \strlen($name)),
                    true,
                );
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
}
