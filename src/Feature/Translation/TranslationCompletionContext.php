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

    public static function fromTwig(string $text, Position $position, PositionConverter $converter, TwigDirectiveLocator $directives): ?self
    {
        $cursor = $converter->toByteOffset($text, $position);
        $before = substr($text, 0, $cursor);
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
            return new self(
                'key',
                TwigStringDecoder::decode($content),
                new Range($converter->toPosition($text, $start), $position),
                quote: $quote,
            );
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
}
