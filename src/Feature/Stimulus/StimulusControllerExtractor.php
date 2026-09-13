<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\JavaScript\JavaScriptToken;
use Symfony\Lsp\Parser\JavaScript\JavaScriptTokenKind;
use Symfony\Lsp\Parser\JavaScript\JavaScriptTokens;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathPolicy;
use Symfony\Lsp\Project\ProjectPathResolver;

final class StimulusControllerExtractor
{
    private const LAZY_COMMENT_PATTERN = '/^\/[\/*]!?\s*stimulusFetch:\s*[\'"]lazy[\'"]/i';
    private const LIFECYCLE_METHODS = ['connect', 'constructor', 'disconnect', 'initialize'];
    private const APPLICATION_IDENTIFIERS = ['application', 'this.application'];
    private const MEMBER_ARRAYS = ['targets' => StimulusMemberKind::Target, 'outlets' => StimulusMemberKind::Outlet, 'classes' => StimulusMemberKind::ClassName];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly ProjectPathResolver $pathResolver,
        private readonly StimulusControllerNameNormalizer $controllerNameNormalizer,
    ) {
    }

    /** @return list<StimulusControllerDeclaration> */
    public function extract(Project $project, string $uri, string $text, JavaScriptTokens $tokens): array
    {
        $name = $this->controllerName($project, $uri);
        if (null === $name) {
            return $this->registrations($project, $uri, $text, $tokens);
        }

        [$declarationOffset, $declarationLength, $bodyIndex] = $this->exportedClass($tokens);

        return [
            new StimulusControllerDeclaration(
                $name,
                $uri,
                $this->converter->toRange($text, $declarationOffset, $declarationLength),
                null === $bodyIndex ? [] : $this->members($tokens, $text, $bodyIndex),
                $this->isLazy($tokens),
            ),
            ...$this->registrations($project, $uri, $text, $tokens),
        ];
    }

    /** @return array{int, int, int|null} */
    private function exportedClass(JavaScriptTokens $tokens): array
    {
        for ($index = 0, $count = $tokens->count(); $index < $count; ++$index) {
            if (!$tokens->isIdentifier($index, 'export') || !$tokens->isIdentifier($index + 1, 'default')) {
                continue;
            }
            $classIndex = $tokens->isIdentifier($index + 2, 'abstract') ? $index + 3 : $index + 2;
            $class = $tokens->isIdentifier($classIndex, 'class') ? $tokens->at($classIndex) : null;
            if (null === $class) {
                continue;
            }
            $export = $tokens->at($index);
            $declarationOffset = null === $export ? 0 : $export->offset;
            $declarationLength = $class->offset + $class->length() - $declarationOffset;
            for ($body = $classIndex + 1; $body < $count; ++$body) {
                if ($tokens->isPunctuator($body, '{')) {
                    return [$declarationOffset, $declarationLength, $body];
                }
            }

            return [$declarationOffset, $declarationLength, null];
        }

        return [0, 0, null];
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
            if (JavaScriptTokenKind::Punctuator === $token->kind && \in_array($token->value, ['{', '[', '('], true)) {
                ++$depth;
                continue;
            }
            if (JavaScriptTokenKind::Punctuator === $token->kind && \in_array($token->value, ['}', ']', ')'], true)) {
                --$depth;
                continue;
            }
            if (0 !== $depth || JavaScriptTokenKind::Identifier !== $token->kind) {
                continue;
            }
            if ('static' === $token->value) {
                array_push($members, ...$this->staticMembers($tokens, $text, $index));
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
    private function staticMembers(JavaScriptTokens $tokens, string $text, int $index): array
    {
        $property = $tokens->identifier($index + 1);
        if (null === $property || !$tokens->isPunctuator($index + 2, '=')) {
            return [];
        }
        if (isset(self::MEMBER_ARRAYS[$property->value]) && $tokens->isPunctuator($index + 3, '[')) {
            $close = $tokens->closingDelimiter($index + 3);

            return null === $close ? [] : array_map(
                fn (JavaScriptToken $string): StimulusMember => new StimulusMember($string->value, self::MEMBER_ARRAYS[$property->value], $this->converter->toRange($text, $string->offset, $string->length())),
                $tokens->stringsBetween($index + 3, $close),
            );
        }
        if ('values' !== $property->value || !$tokens->isPunctuator($index + 3, '{')) {
            return [];
        }

        return $this->valueMembers($tokens, $text, $index + 3);
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
            if (JavaScriptTokenKind::Punctuator === $token->kind && \in_array($token->value, ['{', '[', '('], true)) {
                ++$depth;
            } elseif (JavaScriptTokenKind::Punctuator === $token->kind && \in_array($token->value, ['}', ']', ')'], true)) {
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

    /** @return list<StimulusControllerDeclaration> */
    private function registrations(Project $project, string $uri, string $text, JavaScriptTokens $tokens): array
    {
        if (!$this->isAssetFile($project, $uri)) {
            return [];
        }
        $applications = $this->applicationIdentifiers($tokens);
        $declarations = [];
        for ($index = 0, $count = $tokens->count(); $index < $count; ++$index) {
            if (!$tokens->isIdentifier($index, 'register')
                || !$tokens->isPunctuator($index + 1, '(')
                || !$tokens->isString($index + 2)
                || !$tokens->isPunctuator($index + 3, ',')
            ) {
                continue;
            }
            $name = $tokens->at($index + 2);
            if (null === $name || '' === $name->value || !\in_array($tokens->receiver($index), $applications, true)) {
                continue;
            }
            $declarations[] = new StimulusControllerDeclaration(
                $name->value,
                $uri,
                $this->converter->toRange($text, $name->offset, $name->length()),
                [],
                false,
            );
        }

        return $declarations;
    }

    /** @return list<string> */
    private function applicationIdentifiers(JavaScriptTokens $tokens): array
    {
        $identifiers = self::APPLICATION_IDENTIFIERS;
        for ($index = 0, $count = $tokens->count(); $index < $count; ++$index) {
            $target = $tokens->identifier($index);
            if (null === $target || $tokens->isPunctuator($index - 1, '.') || !$tokens->isPunctuator($index + 1, '=')) {
                continue;
            }
            $factory = $tokens->isIdentifier($index + 2, 'await') ? $index + 3 : $index + 2;
            if (($tokens->isIdentifier($factory, 'startStimulusApp') && $tokens->isPunctuator($factory + 1, '('))
                || ($tokens->isIdentifier($factory, 'Application') && $tokens->isPunctuator($factory + 1, '.') && $tokens->isIdentifier($factory + 2, 'start') && $tokens->isPunctuator($factory + 3, '('))
            ) {
                $identifiers[] = $target->value;
            }
        }

        return $identifiers;
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

    private function controllerName(Project $project, string $uri): ?string
    {
        $path = $this->pathResolver->relative($project, $uri);
        if (null === $path || !preg_match('#^assets/(?:[^/]+/)*?controllers/(.*?)(?:_|-)controller\.[jt]s$#', $path, $match)) {
            return null;
        }
        if ([] !== array_intersect(explode('/', $path), ProjectPathPolicy::EXCLUDED_DIRECTORIES)) {
            return null;
        }

        return $this->controllerNameNormalizer->normalize($match[1]);
    }

    private function isAssetFile(Project $project, string $uri): bool
    {
        $path = $this->pathResolver->relative($project, $uri);
        if (null === $path) {
            return false;
        }
        $segments = explode('/', $path);
        array_pop($segments);

        return \in_array('assets', $segments, true) && [] === array_intersect($segments, ProjectPathPolicy::EXCLUDED_DIRECTORIES);
    }
}
