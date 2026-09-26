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

    public static function fromTwig(string $text, Position $position, PositionConverter $converter): ?self
    {
        $cursor = $converter->toByteOffset($text, $position);
        $before = substr($text, 0, $cursor);
        if (!preg_match(
            '/(?:(?:{%\s*(?:extends|include|embed|import|from|use)\s+)|(?:\binclude\s*\(\s*(?:template\s*[:=]\s*)?)|(?:\bsource\s*\(\s*(?:name\s*[:=]\s*)?))([\'\"])([^\'\"]*)$/s',
            $before,
            $matches,
            \PREG_OFFSET_CAPTURE,
        )) {
            return null;
        }

        return new self(
            $matches[2][0],
            new Range($converter->toPosition($text, $matches[2][1]), $position),
        );
    }
}
