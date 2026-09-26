<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Twig\TwigCallSyntax;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;

final class AssetCompletionContextResolver
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigCommentParser $commentParser,
        private readonly TwigDirectiveLocator $directives,
    ) {
    }

    public function resolve(string $languageId, string $text, int $offset): ?AssetCompletionContext
    {
        if ('twig' !== $languageId) {
            return null;
        }
        $masked = $this->commentParser->mask($text);
        if (!$this->directives->insideDirective($masked, $offset)) {
            return null;
        }
        $before = substr($masked, 0, $offset);
        if (preg_match('/\b(asset)\s*\(\s*(?:path\s*[:=](?![=>])\s*)?["\']([A-Za-z0-9_@.\/-]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)
            && TwigCallSyntax::isFunctionCall($before, $match[1][1])
        ) {
            return $this->context(AssetSymbolKind::Asset, $match[2][0], $text, $match[2][1]);
        }
        if (preg_match('/\b(importmap)\s*\(\s*(?:entryPoint\s*[:=](?![=>])\s*)?(?:\[[^\]]*)?["\']([A-Za-z0-9_@.\/-]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)
            && TwigCallSyntax::isFunctionCall($before, $match[1][1])
        ) {
            return $this->context(AssetSymbolKind::Entrypoint, $match[2][0], $text, $match[2][1]);
        }

        return null;
    }

    private function context(AssetSymbolKind $kind, string $prefix, string $text, int $offset): AssetCompletionContext
    {
        return new AssetCompletionContext($kind, $prefix, $this->converter->toRange($text, $offset, \strlen($prefix)));
    }
}
