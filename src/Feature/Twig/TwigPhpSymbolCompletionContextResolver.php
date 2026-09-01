<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;

final class TwigPhpSymbolCompletionContextResolver
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigCommentParser $comments,
        private readonly TwigDirectiveLocator $directives,
    ) {
    }

    public function resolve(string $text, int $offset): ?TwigPhpSymbolCompletionContext
    {
        $masked = $this->comments->mask($text);
        if (!$this->directives->insideDirective($masked, $offset)) {
            return null;
        }
        $before = substr($masked, 0, $offset);
        if (preg_match('~\benum\s*\(\s*(?:enum\s*[:=]\s*)?(?<quote>[\'\"])(?<class>[A-Za-z0-9_\x7f-\xff\\\\]*)\k<quote>\s*\)\s*\.\s*(?<prefix>[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*)?$~s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            $className = $this->decodeClassName($match['class'][0]);
            $prefix = $match['prefix'][0] ?? '';
            if (null === $className) {
                return null;
            }
            $start = $offset - \strlen($prefix);

            return new TwigPhpSymbolCompletionContext(
                TwigPhpSymbolCompletionKind::EnumCase,
                $prefix,
                $this->completionRange($text, $start, $offset, false),
                $className,
            );
        }
        if (preg_match('~\bconstant\s*\(\s*(?:constant\s*[:=]\s*)?(?<quote>[\'\"])(?<value>[A-Za-z0-9_\x7f-\xff\\\\:]*)$~s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            $raw = $match['value'][0];
            $separator = strrpos($raw, '::');
            if (false !== $separator) {
                $className = $this->decodeClassName(substr($raw, 0, $separator));
                $prefix = substr($raw, $separator + 2);
                if (null === $className || !$this->validIdentifierPrefix($prefix)) {
                    return null;
                }
                $start = $offset - \strlen($prefix);

                return new TwigPhpSymbolCompletionContext(
                    TwigPhpSymbolCompletionKind::ConstantMember,
                    $prefix,
                    $this->completionRange($text, $start, $offset, false),
                    $className,
                );
            }
            $prefix = $this->decodeClassPrefix($raw);
            if (null === $prefix) {
                return null;
            }

            return new TwigPhpSymbolCompletionContext(
                TwigPhpSymbolCompletionKind::ConstantType,
                $prefix,
                $this->completionRange($text, $match['value'][1], $offset, true),
            );
        }
        if (preg_match('~\b(?<function>enum_cases|enum)\s*\(\s*(?:enum\s*[:=]\s*)?(?<quote>[\'\"])(?<class>[A-Za-z0-9_\x7f-\xff\\\\]*)$~s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            $prefix = $this->decodeClassPrefix($match['class'][0]);
            if (null === $prefix) {
                return null;
            }

            return new TwigPhpSymbolCompletionContext(
                TwigPhpSymbolCompletionKind::EnumType,
                $prefix,
                $this->completionRange($text, $match['class'][1], $offset, true),
            );
        }

        return null;
    }

    private function decodeClassName(string $raw): ?string
    {
        $name = $this->decodeClassPrefix($raw, true);

        return null === $name ? null : ltrim($name, '\\');
    }

    private function decodeClassPrefix(string $raw, bool $complete = false): ?string
    {
        $decoded = '';
        for ($offset = 0, $length = \strlen($raw); $offset < $length; ++$offset) {
            if ('\\' !== $raw[$offset]) {
                $decoded .= $raw[$offset];
                continue;
            }
            if ('\\' !== ($raw[$offset + 1] ?? null)) {
                return null;
            }
            $decoded .= '\\';
            ++$offset;
        }
        if ('' === $decoded) {
            return $complete ? null : '';
        }
        $name = str_starts_with($decoded, '\\') ? substr($decoded, 1) : $decoded;
        if ('' === $name || str_starts_with($name, '\\')) {
            return null;
        }
        $segments = explode('\\', $name);
        foreach ($segments as $index => $segment) {
            if ('' === $segment && !$complete && $index === array_key_last($segments)) {
                continue;
            }
            if (!$this->validIdentifier($segment)) {
                return null;
            }
        }

        return $decoded;
    }

    private function completionRange(string $text, int $start, int $cursor, bool $className): Range
    {
        $end = $cursor;
        $length = \strlen($text);
        $pattern = $className ? '/[A-Za-z0-9_\x7f-\xff\\\\]/' : '/[A-Za-z0-9_\x7f-\xff]/';
        while ($end < $length && 1 === preg_match($pattern, $text[$end])) {
            ++$end;
        }

        return $this->converter->toRange($text, $start, $end - $start);
    }

    private function validIdentifier(string $value): bool
    {
        return 1 === preg_match('/^[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*$/D', $value);
    }

    private function validIdentifierPrefix(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*$/D', $value);
    }
}
