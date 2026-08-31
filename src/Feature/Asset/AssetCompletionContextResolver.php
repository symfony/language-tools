<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;

final class AssetCompletionContextResolver
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigCommentParser $commentParser,
    ) {
    }

    public function resolve(string $languageId, string $text, int $offset): ?AssetCompletionContext
    {
        if ('twig' !== $languageId) {
            return null;
        }
        $before = substr($this->commentParser->mask($text), 0, $offset);
        if (preg_match('/\basset\s*\(\s*["\']([A-Za-z0-9_@.\/-]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(AssetSymbolKind::Asset, $match[1][0], $text, $match[1][1]);
        }
        if (preg_match('/\bimportmap\s*\(\s*(?:\[[^\]]*)?["\']([A-Za-z0-9_@.\/-]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(AssetSymbolKind::Entrypoint, $match[1][0], $text, $match[1][1]);
        }

        return null;
    }

    private function context(AssetSymbolKind $kind, string $prefix, string $text, int $offset): AssetCompletionContext
    {
        return new AssetCompletionContext($kind, $prefix, $this->converter->toRange($text, $offset, \strlen($prefix)));
    }
}
