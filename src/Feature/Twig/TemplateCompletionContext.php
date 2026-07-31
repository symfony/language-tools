<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class TemplateCompletionContext
{
    public function __construct(private readonly string $prefix, private readonly Range $range)
    {
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function range(): Range
    {
        return $this->range;
    }

    public static function create(string $languageId, string $text, Position $position, PositionConverter $converter): ?self
    {
        $cursor = $converter->toByteOffset($text, $position);
        $before = substr($text, 0, $cursor);
        $pattern = 'php' === $languageId
            ? '/(?:->|::)(?:render|renderView)\s*\(\s*([\'\"])([^\'\"]*)$/s'
            : ('twig' === $languageId
                ? '/(?:(?:{%\s*(?:extends|include|embed|import|from|use)\s+)|(?:\b(?:include|source)\s*\())([\'\"])([^\'\"]*)$/s'
                : null);
        if (null === $pattern || !preg_match($pattern, $before, $matches, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return new self(
            $matches[2][0],
            new Range($converter->toPosition($text, $matches[2][1]), $position),
        );
    }
}
