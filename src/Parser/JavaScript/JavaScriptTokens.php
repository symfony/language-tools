<?php

namespace Symfony\Lsp\Parser\JavaScript;

final class JavaScriptTokens
{
    private const DELIMITERS = ['(' => ')', '[' => ']', '{' => '}'];

    /**
     * @param list<JavaScriptToken> $tokens
     * @param list<JavaScriptToken> $comments
     */
    public function __construct(
        private readonly array $tokens,
        private readonly array $comments,
    ) {
    }

    public function count(): int
    {
        return \count($this->tokens);
    }

    public function at(int $index): ?JavaScriptToken
    {
        return $this->tokens[$index] ?? null;
    }

    /** @return list<JavaScriptToken> */
    public function comments(): array
    {
        return $this->comments;
    }

    public function isIdentifier(int $index, string $value): bool
    {
        $token = $this->at($index);

        return null !== $token && JavaScriptTokenKind::Identifier === $token->kind && $value === $token->value;
    }

    public function isPunctuator(int $index, string $value): bool
    {
        $token = $this->at($index);

        return null !== $token && JavaScriptTokenKind::Punctuator === $token->kind && $value === $token->value;
    }

    public function isString(int $index): bool
    {
        $token = $this->at($index);

        return null !== $token && JavaScriptTokenKind::String === $token->kind;
    }

    public function identifier(int $index): ?JavaScriptToken
    {
        $token = $this->at($index);

        return null !== $token && JavaScriptTokenKind::Identifier === $token->kind ? $token : null;
    }

    /** Resolves `foo` or `this.foo` for a member access whose member identifier sits at $index. */
    public function receiver(int $index): ?string
    {
        if (!$this->isPunctuator($index - 1, '.')) {
            return null;
        }
        $token = $this->identifier($index - 2);
        if (null === $token) {
            return null;
        }
        if (!$this->isPunctuator($index - 3, '.')) {
            return $token->value;
        }

        return $this->isIdentifier($index - 4, 'this') ? 'this.'.$token->value : null;
    }

    public function closingDelimiter(int $open): ?int
    {
        $token = $this->at($open);
        if (null === $token || JavaScriptTokenKind::Punctuator !== $token->kind || !isset(self::DELIMITERS[$token->value])) {
            return null;
        }
        $closing = self::DELIMITERS[$token->value];
        $depth = 0;
        for ($index = $open, $count = $this->count(); $index < $count; ++$index) {
            if ($this->isPunctuator($index, $token->value)) {
                ++$depth;
            } elseif ($this->isPunctuator($index, $closing) && 0 === --$depth) {
                return $index;
            }
        }

        return null;
    }

    /** @return list<JavaScriptToken> */
    public function stringsBetween(int $open, int $close): array
    {
        $strings = [];
        for ($index = $open + 1; $index < $close; ++$index) {
            if ($this->isString($index)) {
                $strings[] = $this->tokens[$index];
            }
        }

        return $strings;
    }
}
