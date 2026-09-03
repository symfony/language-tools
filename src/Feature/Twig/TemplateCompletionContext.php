<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class TemplateCompletionContext
{
    public function __construct(public readonly string $prefix, public readonly Range $range, public readonly bool $phpRenderCall = false)
    {
    }

    public static function create(string $languageId, string $text, Position $position, PositionConverter $converter): ?self
    {
        $cursor = $converter->toByteOffset($text, $position);
        $before = substr($text, 0, $cursor);
        if ('php' === $languageId) {
            foreach ([
                ['/->(?:render|renderView)\s*\(\s*(?:(?:view|name)\s*:)?\s*([\'\"])([^\'\"]*)$/s', true],
                ['/#\[\s*(?:[^\[\]]*,\s*)?\\\\?(?:Symfony\\\\Bridge\\\\Twig\\\\Attribute\\\\)?Template\s*\(\s*(?:template\s*:)?\s*([\'\"])([^\'\"]*)$/s', false],
            ] as [$pattern, $phpRenderCall]) {
                if (preg_match($pattern, $before, $matches, \PREG_OFFSET_CAPTURE)) {
                    return new self(
                        $matches[2][0],
                        new Range($converter->toPosition($text, $matches[2][1]), $position),
                        $phpRenderCall,
                    );
                }
            }

            return null;
        }
        if ('twig' !== $languageId || !preg_match(
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
