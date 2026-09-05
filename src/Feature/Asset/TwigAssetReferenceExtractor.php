<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigDocument;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Parser\Twig\TwigStringLiteral;

final class TwigAssetReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigDocumentParser $parser,
        private readonly TwigCallArgumentResolver $arguments,
    ) {
    }

    /** @return list<AssetSourceSymbol> */
    public function extract(string $uri, string $text): array
    {
        $document = $this->parser->parse($text);
        $symbols = [];
        foreach ($document->nodesOfType('function_call') as $call) {
            $function = $document->directChild($call, 'function_identifier');
            $name = null === $function ? null : $document->text($function);
            if ('asset' !== $name && 'importmap' !== $name) {
                continue;
            }
            $arguments = $this->arguments->resolve($document, $call);
            if ('importmap' === $name) {
                foreach ($this->entrypoints($document, $arguments->get(0)) as $entrypoint) {
                    $symbols[] = $this->symbol(AssetSymbolKind::Entrypoint, $entrypoint, $uri, $text);
                }

                continue;
            }
            $path = $arguments->get(0, 'path');
            $literal = null === $path ? null : $document->soleStringLiteral($path);
            if (null === $literal || str_starts_with($literal->value, '/') || null !== $arguments->get(1, 'packageName')) {
                continue;
            }
            $symbols[] = $this->symbol(AssetSymbolKind::Asset, $literal, $uri, $text);
        }

        return $this->unique($symbols);
    }

    /**
     * Accepts a single entrypoint or a list of entrypoints.
     *
     * @return list<TwigStringLiteral>
     */
    private function entrypoints(TwigDocument $document, ?TreeSitterNode $argument): array
    {
        if (null === $argument) {
            return [];
        }
        if (null !== $literal = $document->soleStringLiteral($argument)) {
            return '' === $literal->value ? [] : [$literal];
        }
        $value = trim($document->text($argument));
        $array = $document->firstDescendant($argument, 'array');
        if (null === $array || !str_starts_with($value, '[') || !str_ends_with($value, ']')) {
            return [];
        }
        $literals = [];
        foreach ($document->children($array) as $child) {
            $literal = $document->stringLiteral($child);
            if (null !== $literal && '' !== $literal->value) {
                $literals[] = $literal;
            }
        }

        return $literals;
    }

    private function symbol(AssetSymbolKind $kind, TwigStringLiteral $literal, string $uri, string $text): AssetSourceSymbol
    {
        return new AssetSourceSymbol(
            $kind,
            $literal->value,
            $uri,
            $this->converter->toRange($text, $literal->startOffset, $literal->endOffset - $literal->startOffset),
            false,
        );
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
