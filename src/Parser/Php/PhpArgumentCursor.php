<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpArgumentCursor
{
    private function __construct(
        public readonly PhpMethodCall|PhpObjectCreation|PhpAttribute|PhpArgumentList $call,
        public readonly PhpArgument $argument,
        public readonly int $position,
        public readonly ?string $name,
        public readonly ?string $quote,
        public readonly string $prefix,
        public readonly int $prefixStartOffset,
        private readonly int $literalDepth,
        private readonly bool $literalStartsItem,
        private readonly bool $argumentIsArray,
        private readonly bool $literalIsArgument,
        private readonly bool $literalIsItem,
    ) {
    }

    public static function at(PhpMethodCall|PhpObjectCreation|PhpAttribute|PhpArgumentList $call, int $offset): ?self
    {
        foreach ($call->arguments as $position => $argument) {
            if ($offset < $argument->startOffset || $offset > $argument->endOffset) {
                continue;
            }
            $expression = $argument->expression;
            $start = $argument->expressionStartOffset;
            $literal = \is_string($expression) && \is_int($start) && $offset >= $start
                ? self::openLiteral(substr($expression, 0, $offset - $start))
                : null;
            if (null === $literal) {
                return new self($call, $argument, $position, $argument->name, null, '', $offset, 0, false, false, false, false);
            }
            $whole = null === $argument->stringLiteral ? self::openLiteral((string) $expression) : null;

            return new self(
                $call,
                $argument,
                $position,
                $argument->name,
                $literal['quote'],
                PhpStringLiteralDecoder::decode($literal['quote'], $literal['content']),
                (int) $start + $literal['contentStart'],
                $literal['depth'],
                $literal['startsItem'],
                $literal['argumentIsArray'],
                null !== $argument->stringLiteral || ($whole['contentStart'] ?? null) === $literal['contentStart'],
                self::literalEndsItem((string) $expression, $literal['contentStart'] - 1),
            );
        }

        return null;
    }

    public function isPositional(int $position): bool
    {
        return null === $this->name && !$this->argument->unpacked && $position === $this->position;
    }

    public function isNamedOrPositional(string $name, int $position): bool
    {
        return $name === $this->name || $this->isPositional($position);
    }

    public function isArgumentLiteral(): bool
    {
        return null !== $this->quote && 0 === $this->literalDepth && $this->literalStartsItem && $this->literalIsArgument;
    }

    public function isArrayItemLiteral(): bool
    {
        return null !== $this->quote && 1 === $this->literalDepth && $this->literalStartsItem && $this->argumentIsArray && $this->literalIsItem;
    }

    public function isArrayItemValueLiteral(): bool
    {
        return null !== $this->quote && 1 === $this->literalDepth && !$this->literalStartsItem && $this->argumentIsArray && $this->literalIsItem;
    }

    private static function literalEndsItem(string $expression, int $quoteOffset): bool
    {
        $prefix = \strlen('<?php ');
        $tokens = \PhpToken::tokenize('<?php '.$expression);
        $count = \count($tokens);
        for ($index = 0; $index < $count && $tokens[$index]->pos - $prefix !== $quoteOffset; ++$index) {
        }
        if ($index === $count || \T_ENCAPSED_AND_WHITESPACE === $tokens[$index]->id) {
            return true;
        }
        if ('"' === $tokens[$index]->text) {
            for (++$index; $index < $count && '"' !== $tokens[$index]->text; ++$index) {
            }
            if ($index === $count) {
                return true;
            }
        }
        for (++$index; $index < $count && $tokens[$index]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT]); ++$index) {
        }

        return $index === $count || \in_array($tokens[$index]->text, [',', ']', ')', '=>'], true);
    }

    /**
     * The unterminated string literal the text ends in, with its raw content,
     * the bracket depth it opens at, whether it opens an item and whether the
     * argument itself is the array literal enclosing it.
     *
     * @return array{quote: string, content: string, contentStart: int, depth: int, startsItem: bool, argumentIsArray: bool}|null
     */
    private static function openLiteral(string $text): ?array
    {
        $prefix = \strlen('<?php ');
        $depth = 0;
        $filled = [false];
        $quote = null;
        $contentStart = 0;
        $literalDepth = 0;
        $startsItem = false;
        $interpolated = false;
        $heredoc = false;
        $arrayKeyword = false;
        $argumentIsArray = false;
        foreach (\PhpToken::tokenize('<?php '.$text) as $token) {
            if ($heredoc) {
                $heredoc = \T_END_HEREDOC !== $token->id;

                continue;
            }
            if (null !== $quote) {
                if ($quote === $token->text) {
                    $quote = null;
                } elseif (\T_ENCAPSED_AND_WHITESPACE !== $token->id) {
                    $interpolated = true;
                }

                continue;
            }
            if ($token->is([\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }
            if (\T_ENCAPSED_AND_WHITESPACE === $token->id && str_starts_with($token->text, "'")) {
                return [
                    'quote' => "'",
                    'content' => substr($token->text, 1),
                    'contentStart' => $token->pos - $prefix + 1,
                    'depth' => $depth,
                    'startsItem' => !$filled[$depth],
                    'argumentIsArray' => $argumentIsArray,
                ];
            }
            $startsArrayArgument = 0 === $depth && (('[' === $token->text && !$filled[0]) || ('(' === $token->text && $arrayKeyword));
            $arrayKeyword = 0 === $depth && \T_ARRAY === $token->id && !$filled[0];
            if (\in_array($token->text, ['"', '`'], true)) {
                $quote = $token->text;
                $contentStart = $token->pos - $prefix + 1;
                $literalDepth = $depth;
                $startsItem = !$filled[$depth];
                $interpolated = false;
            } elseif (\T_START_HEREDOC === $token->id) {
                $heredoc = true;
            } elseif (\in_array($token->text, ['(', '[', '{'], true) || $token->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES, \T_ATTRIBUTE])) {
                if (0 === $depth) {
                    $argumentIsArray = $startsArrayArgument;
                }
                $filled[$depth] = true;
                $filled[++$depth] = false;

                continue;
            } elseif (\in_array($token->text, [')', ']', '}'], true)) {
                $depth = max(0, $depth - 1);
            } elseif (',' === $token->text) {
                $filled[$depth] = false;

                continue;
            }
            $filled[$depth] = true;
        }

        if ('"' !== $quote) {
            return null;
        }

        return $interpolated ? null : [
            'quote' => $quote,
            'content' => substr($text, $contentStart),
            'contentStart' => $contentStart,
            'depth' => $literalDepth,
            'startsItem' => $startsItem,
            'argumentIsArray' => $argumentIsArray,
        ];
    }
}
