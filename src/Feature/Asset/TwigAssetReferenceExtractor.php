<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigAssetReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigDocumentParser $parser,
        private readonly TwigCallArgumentResolver $arguments,
        private readonly TwigCommentParser $commentParser,
    ) {
    }

    /** @return list<AssetSourceSymbol> */
    public function extract(string $uri, string $text): array
    {
        $document = $this->parser->parse($text);
        $source = $this->commentParser->mask($text);
        $symbols = [];
        foreach ($document->nodesOfType('function_call') as $call) {
            $function = $document->directChild($call, 'function_identifier');
            if (null === $function || 'asset' !== $document->text($function)) {
                continue;
            }
            $arguments = $this->arguments->resolve($document, $call);
            $path = $arguments->get(0, 'path');
            $literal = null === $path ? null : $document->soleStringLiteral($path);
            if (null === $literal || str_starts_with($literal->value, '/') || null !== $arguments->get(1, 'packageName')) {
                continue;
            }
            $symbols[] = new AssetSourceSymbol(
                AssetSymbolKind::Asset,
                $literal->value,
                $uri,
                $this->converter->toRange($text, $literal->startOffset, $literal->endOffset - $literal->startOffset),
                false,
            );
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
