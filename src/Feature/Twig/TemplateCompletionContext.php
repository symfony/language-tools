<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class TemplateCompletionContext
{
    public function __construct(public readonly string $prefix, public readonly Range $range)
    {
    }

    public static function create(string $languageId, string $text, Position $position, PositionConverter $converter): ?self
    {
        $cursor = $converter->toByteOffset($text, $position);
        $before = substr($text, 0, $cursor);
        $pattern = 'php' === $languageId
            ? '/(?:(?:->|::)(?:render|renderView)\s*\(\s*(?:view\s*:)?|#\[\s*(?:[^\[\]]*,\s*)?\\\\?(?:Symfony\\\\Bridge\\\\Twig\\\\Attribute\\\\)?Template\s*\(\s*(?:template\s*:)?)\s*([\'\"])([^\'\"]*)$/s'
            : ('twig' === $languageId
                ? '/(?:(?:{%\s*(?:extends|include|embed|import|from|use)\s+)|(?:\binclude\s*\(\s*(?:template\s*[:=]\s*)?)|(?:\bsource\s*\(\s*(?:name\s*[:=]\s*)?))([\'\"])([^\'\"]*)$/s'
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
