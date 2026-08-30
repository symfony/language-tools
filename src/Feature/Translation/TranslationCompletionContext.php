<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Twig\TwigStringDecoder;

final class TranslationCompletionContext
{
    public function __construct(
        public readonly string $kind,
        public readonly string $prefix,
        public readonly Range $range,
        public readonly string $domain = 'messages',
        public readonly ?string $key = null,
        public readonly ?string $quote = null,
    ) {
    }

    public static function create(string $languageId, string $text, Position $position, PositionConverter $converter): ?self
    {
        $cursor = $converter->toByteOffset($text, $position);
        $before = substr($text, 0, $cursor);
        if ('php' === $languageId) {
            if (preg_match('/(?:->trans\s*\(\s*(?:id\s*:\s*)?|(?:\bt|new\s+TranslatableMessage)\s*\(\s*(?:message\s*:\s*)?)([\'\"])([^\'\"]*)$/s', $before, $m, \PREG_OFFSET_CAPTURE)) {
                return self::context('key', $m[2], $text, $position, $converter);
            }
            if (preg_match('/(?:->trans|\bt|new\s+TranslatableMessage)\s*\(\s*([\'\"])([^\'\"]+)\1\s*,\s*\[[^\]]*[\'\"](%?[^\'\"]*)$/s', $before, $m, \PREG_OFFSET_CAPTURE)) {
                return self::context('placeholder', $m[3], $text, $position, $converter, 'messages', $m[2][0]);
            }
            if (preg_match('/(?:->trans|\bt|new\s+TranslatableMessage)\s*\(\s*([\'\"])([^\'\"]+)\1\s*,\s*\[[^\]]*\]\s*,\s*([\'\"])([^\'\"]*)$/s', $before, $m, \PREG_OFFSET_CAPTURE)) {
                return self::context('domain', $m[4], $text, $position, $converter);
            }
            if (preg_match('/->trans\s*\(.*?,\s*\[[^\]]*\]\s*,\s*([\'\"])([^\'\"]+)\1\s*,\s*([\'\"])([^\'\"]*)$/s', $before, $m, \PREG_OFFSET_CAPTURE)) {
                return self::context('locale', $m[4], $text, $position, $converter);
            }
        }
        if ('twig' === $languageId) {
            if (preg_match('/\b(?:trans|t)\s*\(\s*(?|(\')((?:\\\\.|[^\'\\\\])*)$|(\")((?:\\\\.|[^\"#\\\\])*)$)/s', $before, $m, \PREG_OFFSET_CAPTURE)) {
                return self::context('key', $m[2], $text, $position, $converter, quote: $m[1][0]);
            }
            if (preg_match('/(?|(\')((?:\\\\.|[^\'\\\\])*)$|(\")((?:\\\\.|[^\"#\\\\])*)$)/s', $before, $m, \PREG_OFFSET_CAPTURE)
                && preg_match('/^'.preg_quote($m[1][0], '/').'\s*\|\s*trans\b/', substr($text, $cursor))
            ) {
                return self::context('key', $m[2], $text, $position, $converter, quote: $m[1][0]);
            }
        }

        return null;
    }

    /** @param array{0: string, 1: int} $match */
    private static function context(string $kind, array $match, string $text, Position $position, PositionConverter $converter, string $domain = 'messages', ?string $key = null, ?string $quote = null): self
    {
        $prefix = ltrim($match[0], '%');
        $offset = $match[1] + (str_starts_with($match[0], '%') ? 1 : 0);
        if (null !== $quote) {
            $prefix = TwigStringDecoder::decode($prefix);
        }

        return new self($kind, $prefix, new Range($converter->toPosition($text, $offset), $position), $domain, $key, $quote);
    }
}
