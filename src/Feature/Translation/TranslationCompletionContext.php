<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Parser\Twig\TwigStringDecoder;

final class TranslationCompletionContext
{
    private const TWIG_STRING_CONTENT = [
        "'" => '/^(?:\\\\.|[^\'\\\\])*$/Ds',
        '"' => '/^(?:\\\\.|[^"#\\\\])*$/Ds',
    ];
    private const TWIG_TRANS_FILTER = [
        "'" => '/^(?:\\\\.|[^\'\\\\])*\'\s*\|\s*trans\b/s',
        '"' => '/^(?:\\\\.|[^"#\\\\])*"\s*\|\s*trans\b/s',
    ];

    public function __construct(
        public readonly string $kind,
        public readonly string $prefix,
        public readonly Range $range,
        public readonly string $domain = 'messages',
        public readonly ?string $key = null,
        public readonly ?string $quote = null,
    ) {
    }

    public static function create(string $languageId, string $text, Position $position, PositionConverter $converter, TwigDirectiveLocator $directives): ?self
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
            $directive = $directives->directiveStart($text, $cursor);
            if (null === $directive || null === $string = self::twigOpenString($before, $directive)) {
                return null;
            }
            [$quote, $start] = $string;
            $content = substr($before, $start);
            if (!preg_match(self::TWIG_STRING_CONTENT[$quote], $content)) {
                return null;
            }
            if (
                preg_match('/\b(?:trans|t)\s*\(\s*$/D', substr($before, 0, $start - 1))
                || preg_match(self::TWIG_TRANS_FILTER[$quote], substr($text, $cursor))
            ) {
                return self::context('key', [$content, $start], $text, $position, $converter, quote: $quote);
            }
        }

        return null;
    }

    /**
     * The quote and content offset of the string the cursor sits in, scanning
     * the open directive only: quotes in the surrounding markup are not Twig
     * string delimiters.
     *
     * @return array{string, int}|null
     */
    private static function twigOpenString(string $before, int $directive): ?array
    {
        $quote = null;
        $start = 0;
        for ($offset = $directive, $length = \strlen($before); $offset < $length; ++$offset) {
            $byte = $before[$offset];
            if (null === $quote) {
                if ("'" === $byte || '"' === $byte) {
                    $quote = $byte;
                    $start = $offset + 1;
                }

                continue;
            }
            if ('\\' === $byte) {
                ++$offset;

                continue;
            }
            if ($quote === $byte) {
                $quote = null;
            }
        }

        return null === $quote ? null : [$quote, $start];
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
