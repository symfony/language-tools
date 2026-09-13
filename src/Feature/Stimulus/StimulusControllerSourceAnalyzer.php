<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\JavaScript\JavaScriptToken;
use Symfony\Lsp\Parser\JavaScript\JavaScriptTokenKind;
use Symfony\Lsp\Parser\JavaScript\JavaScriptTokens;

final class StimulusControllerSourceAnalyzer
{
    private const LAZY_COMMENT_PATTERN = '/^\/[\/*]!?\s*stimulusFetch:\s*[\'"]lazy[\'"]/i';
    private const LIFECYCLE_METHODS = ['connect', 'constructor', 'disconnect', 'initialize'];
    private const MEMBER_ARRAYS = ['targets' => StimulusMemberKind::Target, 'outlets' => StimulusMemberKind::Outlet, 'classes' => StimulusMemberKind::ClassName];
    private const OPENING_DELIMITERS = ['{', '[', '('];
    private const CLOSING_DELIMITERS = ['}', ']', ')'];
    private const MAXIMUM_INHERITANCE_DEPTH = 8;

    public function __construct(
        private readonly PositionConverter $converter,
    ) {
    }

    public function analyze(string $text, JavaScriptTokens $tokens): StimulusControllerSource
    {
        $exported = $this->defaultExportedClass($tokens);
        if (null === $exported) {
            return new StimulusControllerSource($this->converter->toRange($text, 0, 0), [], $this->isLazy($tokens));
        }
        [$declarationIndex, $classIndex] = $exported;

        return new StimulusControllerSource(
            $this->declarationRange($tokens, $text, $declarationIndex, $classIndex),
            $this->inheritedMembers($tokens, $text, $classIndex),
            $this->isLazy($tokens),
        );
    }

    /**
     * Resolves the class behind the default export, including the
     * `var X = class extends Controller {}; export { X as default }` shape that
     * bundled Symfony UX packages ship.
     *
     * @return array{int, int}|null the declaration and `class` token indexes
     */
    private function defaultExportedClass(JavaScriptTokens $tokens): ?array
    {
        for ($index = 0, $count = $tokens->count(); $index < $count; ++$index) {
            if (!$tokens->isIdentifier($index, 'export')) {
                continue;
            }
            if ($tokens->isIdentifier($index + 1, 'default')) {
                $classIndex = $tokens->isIdentifier($index + 2, 'abstract') ? $index + 3 : $index + 2;
                if ($tokens->isIdentifier($classIndex, 'class')) {
                    return [$index, $classIndex];
                }
                $binding = $tokens->identifier($index + 2);
                $classIndex = null === $binding ? null : $this->classBinding($tokens, $binding->value);

                return null === $classIndex ? null : [$classIndex, $classIndex];
            }
            $classIndex = $this->reexportedDefaultClass($tokens, $index);
            if (null !== $classIndex) {
                return [$classIndex, $classIndex];
            }
        }

        return null;
    }

    private function reexportedDefaultClass(JavaScriptTokens $tokens, int $index): ?int
    {
        if (!$tokens->isPunctuator($index + 1, '{')) {
            return null;
        }
        $close = $tokens->closingDelimiter($index + 1);
        if (null === $close) {
            return null;
        }
        for ($entry = $index + 2; $entry < $close; ++$entry) {
            if (!$tokens->isIdentifier($entry, 'as') || !$tokens->isIdentifier($entry + 1, 'default')) {
                continue;
            }
            $binding = $tokens->identifier($entry - 1);

            return null === $binding ? null : $this->classBinding($tokens, $binding->value);
        }

        return null;
    }

    private function classBinding(JavaScriptTokens $tokens, string $name): ?int
    {
        for ($index = 0, $count = $tokens->count(); $index < $count; ++$index) {
            if (!$tokens->isIdentifier($index, 'class') || $tokens->isPunctuator($index - 1, '.')) {
                continue;
            }
            if ($name === $tokens->identifier($index + 1)?->value && !$tokens->isIdentifier($index + 1, 'extends')) {
                return $index;
            }
            if ($tokens->isPunctuator($index - 1, '=') && $name === $tokens->identifier($index - 2)?->value) {
                return $index;
            }
        }

        return null;
    }

    /** @return array{int|null, string|null, int|null} the name, superclass name and body token indexes */
    private function classHead(JavaScriptTokens $tokens, int $classIndex): array
    {
        $index = $classIndex + 1;
        $name = null;
        if (!$tokens->isIdentifier($index, 'extends') && null !== $tokens->identifier($index)) {
            $name = $index++;
        }
        $superclass = null;
        if ($tokens->isIdentifier($index, 'extends') && null !== $parent = $tokens->identifier($index + 1)) {
            $superclass = $tokens->isPunctuator($index + 2, '{') ? $parent->value : null;
        }
        for ($count = $tokens->count(); $index < $count; ++$index) {
            if ($tokens->isPunctuator($index, '{')) {
                return [$name, $superclass, $index];
            }
        }

        return [$name, $superclass, null];
    }

    private function className(JavaScriptTokens $tokens, int $classIndex): ?string
    {
        [$name] = $this->classHead($tokens, $classIndex);
        if (null !== $name) {
            return $tokens->identifier($name)?->value;
        }

        return $tokens->isPunctuator($classIndex - 1, '=') ? $tokens->identifier($classIndex - 2)?->value : null;
    }

    /**
     * Collects `Controller.values = {}` assignments, which is how bundlers lower
     * static class properties.
     *
     * @return list<StimulusMember>
     */
    private function assignedStaticMembers(JavaScriptTokens $tokens, string $text, string $name): array
    {
        $members = [];
        for ($index = 0, $count = $tokens->count(); $index < $count; ++$index) {
            if ($name === $tokens->identifier($index)?->value && !$tokens->isPunctuator($index - 1, '.') && $tokens->isPunctuator($index + 1, '.')) {
                array_push($members, ...$this->propertyMembers($tokens, $text, $index + 2));
            }
        }

        return $members;
    }

    private function declarationRange(JavaScriptTokens $tokens, string $text, int $declarationIndex, int $classIndex): Range
    {
        [$name] = $this->classHead($tokens, $classIndex);
        $start = $tokens->at($declarationIndex);
        $end = $tokens->at($name ?? $classIndex);
        if (null === $start || null === $end) {
            return $this->converter->toRange($text, 0, 0);
        }

        return $this->converter->toRange($text, $start->offset, $end->offset + $end->length() - $start->offset);
    }

    /** @return list<StimulusMember> */
    private function inheritedMembers(JavaScriptTokens $tokens, string $text, int $classIndex): array
    {
        $members = [];
        $visited = [];
        for ($depth = 0; $depth < self::MAXIMUM_INHERITANCE_DEPTH && !isset($visited[$classIndex]); ++$depth) {
            $visited[$classIndex] = true;
            [, $superclass, $bodyIndex] = $this->classHead($tokens, $classIndex);
            if (null !== $bodyIndex) {
                array_push($members, ...$this->members($tokens, $text, $bodyIndex));
            }
            $name = $this->className($tokens, $classIndex);
            if (null !== $name) {
                array_push($members, ...$this->assignedStaticMembers($tokens, $text, $name));
            }
            if (null === $superclass || null === $parent = $this->classBinding($tokens, $superclass)) {
                break;
            }
            $classIndex = $parent;
        }

        $unique = [];
        foreach ($members as $member) {
            $unique[$member->kind->value."\0".$member->name] ??= $member;
        }

        return array_values($unique);
    }

    /** @return list<StimulusMember> */
    private function members(JavaScriptTokens $tokens, string $text, int $bodyIndex): array
    {
        $end = $tokens->closingDelimiter($bodyIndex) ?? $tokens->count();
        $members = [];
        $depth = 0;
        for ($index = $bodyIndex + 1; $index < $end; ++$index) {
            $token = $tokens->at($index);
            if (null === $token) {
                break;
            }
            if (JavaScriptTokenKind::Punctuator === $token->kind && \in_array($token->value, self::OPENING_DELIMITERS, true)) {
                ++$depth;
                continue;
            }
            if (JavaScriptTokenKind::Punctuator === $token->kind && \in_array($token->value, self::CLOSING_DELIMITERS, true)) {
                --$depth;
                continue;
            }
            if (0 !== $depth || JavaScriptTokenKind::Identifier !== $token->kind) {
                continue;
            }
            if ('static' === $token->value) {
                array_push($members, ...$this->propertyMembers($tokens, $text, $index + 1));
                continue;
            }
            $method = $this->method($tokens, $index);
            if (null !== $method && !\in_array($method->value, self::LIFECYCLE_METHODS, true)) {
                $members[] = new StimulusMember($method->value, StimulusMemberKind::Action, $this->converter->toRange($text, $method->offset, $method->length()));
            }
        }

        return $members;
    }

    private function method(JavaScriptTokens $tokens, int $index): ?JavaScriptToken
    {
        $token = $tokens->at($index);
        if (null === $token || !$token->startsLine) {
            return null;
        }
        if ('async' === $token->value) {
            $token = $tokens->identifier(++$index);
        }
        if (null === $token || !$tokens->isPunctuator($index + 1, '(')) {
            return null;
        }
        $arguments = $tokens->closingDelimiter($index + 1);
        if (null === $arguments) {
            return null;
        }
        $body = $arguments + 1;
        if ($tokens->isPunctuator($body, ':')) {
            while ($body < $tokens->count() && !$tokens->isPunctuator($body, '{')) {
                ++$body;
            }
        }

        return $tokens->isPunctuator($body, '{') ? $token : null;
    }

    /** @return list<StimulusMember> */
    private function propertyMembers(JavaScriptTokens $tokens, string $text, int $index): array
    {
        $property = $tokens->identifier($index);
        if (null === $property || !$tokens->isPunctuator($index + 1, '=')) {
            return [];
        }
        if (isset(self::MEMBER_ARRAYS[$property->value]) && $tokens->isPunctuator($index + 2, '[')) {
            $close = $tokens->closingDelimiter($index + 2);

            return null === $close ? [] : array_map(
                fn (JavaScriptToken $string): StimulusMember => new StimulusMember($string->value, self::MEMBER_ARRAYS[$property->value], $this->converter->toRange($text, $string->offset, $string->length())),
                $tokens->stringsBetween($index + 2, $close),
            );
        }
        if ('values' !== $property->value || !$tokens->isPunctuator($index + 2, '{')) {
            return [];
        }

        return $this->valueMembers($tokens, $text, $index + 2);
    }

    /** @return list<StimulusMember> */
    private function valueMembers(JavaScriptTokens $tokens, string $text, int $open): array
    {
        $close = $tokens->closingDelimiter($open);
        if (null === $close) {
            return [];
        }
        $members = [];
        $depth = 0;
        for ($index = $open + 1; $index < $close; ++$index) {
            $token = $tokens->at($index);
            if (null === $token) {
                break;
            }
            if (JavaScriptTokenKind::Punctuator === $token->kind && \in_array($token->value, self::OPENING_DELIMITERS, true)) {
                ++$depth;
            } elseif (JavaScriptTokenKind::Punctuator === $token->kind && \in_array($token->value, self::CLOSING_DELIMITERS, true)) {
                --$depth;
            } elseif (0 === $depth
                && JavaScriptTokenKind::Identifier === $token->kind
                && $tokens->isPunctuator($index + 1, ':')
                && ($index === $open + 1 || $tokens->isPunctuator($index - 1, ','))
            ) {
                $members[] = new StimulusMember($token->value, StimulusMemberKind::Value, $this->converter->toRange($text, $token->offset, $token->length()));
            }
        }

        return $members;
    }

    private function isLazy(JavaScriptTokens $tokens): bool
    {
        foreach ($tokens->comments() as $comment) {
            if (1 === preg_match(self::LAZY_COMMENT_PATTERN, $comment->value)) {
                return true;
            }
        }

        return false;
    }
}
