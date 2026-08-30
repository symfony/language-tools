<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpMethodDeclaration;
use Symfony\Lsp\Parser\Php\PhpObjectCreation;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;

final class TwigCallableDeclarationExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $parser,
    ) {
    }

    public function extract(string $uri, string $text): TwigCallableSourceFacts
    {
        $declarations = [];
        $document = $this->parser->parse($text);
        foreach ($document->objectCreations as $creation) {
            $kind = $this->objectKind($creation);
            if (null === $kind) {
                continue;
            }
            $name = $this->literalName(($creation->argument('name') ?? $creation->positionalArgument(0))?->stringLiteral);
            if (null === $name) {
                continue;
            }
            $callable = ($creation->argument('callable') ?? $creation->positionalArgument(1))?->callable;
            $options = $this->objectOptions($creation);
            $declarations[] = new TwigCallableDeclaration(
                kind: $kind,
                name: $name->value,
                uri: $uri,
                range: new Range(
                    $this->converter->toPosition($text, $name->startOffset),
                    $this->converter->toPosition($text, $name->endOffset),
                ),
                className: $callable?->className,
                method: $callable?->method,
                needsEnvironment: $options['needsEnvironment'],
                needsContext: $options['needsContext'],
                variadic: $options['variadic'],
                optionsKnown: $options['known'],
                needsCharset: $options['needsCharset'],
                needsIsSandboxed: $options['needsIsSandboxed'],
            );
        }

        foreach ($document->methodDeclarations as $method) {
            if (!$method->public) {
                continue;
            }
            foreach ($method->attributes as $attribute) {
                $kind = $this->attributeKind($attribute);
                if (null === $kind) {
                    continue;
                }
                $name = $this->literalName(($attribute->argument('name') ?? $attribute->positionalArgument(0))?->stringLiteral);
                if (null === $name) {
                    continue;
                }
                $options = $this->attributeOptions($attribute, $method);
                $declarations[] = new TwigCallableDeclaration(
                    kind: $kind,
                    name: $name->value,
                    uri: $uri,
                    range: new Range(
                        $this->converter->toPosition($text, $name->startOffset),
                        $this->converter->toPosition($text, $name->endOffset),
                    ),
                    className: $method->className,
                    method: $method->name,
                    needsEnvironment: $options['needsEnvironment'],
                    needsContext: $options['needsContext'],
                    variadic: $method->variadic,
                    optionsKnown: $options['known'],
                    needsCharset: $options['needsCharset'],
                    needsIsSandboxed: $options['needsIsSandboxed'],
                );
            }
        }

        return new TwigCallableSourceFacts($uri, $declarations);
    }

    private function literalName(?PhpStringLiteral $name): ?PhpStringLiteral
    {
        return null === $name || '' === $name->value || str_contains($name->value, '\\') ? null : $name;
    }

    /** @return array{needsCharset: bool, needsEnvironment: bool, needsContext: bool, needsIsSandboxed: bool, variadic: bool, known: bool} */
    private function objectOptions(PhpObjectCreation $creation): array
    {
        $argument = $creation->argument('options') ?? $creation->positionalArgument(2);
        if (null === $argument) {
            return ['needsCharset' => false, 'needsEnvironment' => false, 'needsContext' => false, 'needsIsSandboxed' => false, 'variadic' => false, 'known' => true];
        }
        $expression = trim((string) $argument->expression);
        $known = str_starts_with($expression, '[') || 1 === preg_match('/^array\s*\(/i', $expression);

        return [
            'needsCharset' => $this->objectOption($expression, 'needs_charset'),
            'needsEnvironment' => $this->objectOption($expression, 'needs_environment'),
            'needsContext' => $this->objectOption($expression, 'needs_context'),
            'needsIsSandboxed' => $this->objectOption($expression, 'needs_is_sandboxed'),
            'variadic' => $this->objectOption($expression, 'is_variadic'),
            'known' => $known,
        ];
    }

    /** @return array{needsCharset: bool, needsEnvironment: bool, needsContext: bool, needsIsSandboxed: bool, known: bool} */
    private function attributeOptions(PhpAttribute $attribute, PhpMethodDeclaration $method): array
    {
        [$needsCharset, $charsetKnown] = $this->attributeOption($attribute, 'needsCharset', 1, false);
        [$needsEnvironment, $environmentKnown] = $this->attributeOption(
            $attribute,
            'needsEnvironment',
            2,
            'Twig\Environment' === $method->firstParameterType && !$method->firstParameterVariadic,
        );
        [$needsContext, $contextKnown] = $this->attributeOption($attribute, 'needsContext', 3, false);
        [$needsIsSandboxed, $sandboxKnown] = $this->attributeSandboxOption($attribute);

        return [
            'needsCharset' => $needsCharset,
            'needsEnvironment' => $needsEnvironment,
            'needsContext' => $needsContext,
            'needsIsSandboxed' => $needsIsSandboxed,
            'known' => $charsetKnown && $environmentKnown && $contextKnown && $sandboxKnown,
        ];
    }

    /** @return array{bool, bool} */
    private function attributeSandboxOption(PhpAttribute $attribute): array
    {
        if (null !== $attribute->argument('needsIsSandboxed')) {
            return $this->attributeOption($attribute, 'needsIsSandboxed', 4, false);
        }
        $positional = $attribute->positionalArgument(4);
        if (null === $positional || null !== $positional->name) {
            return [false, true];
        }
        $expression = trim((string) $positional->expression);
        if (str_starts_with($expression, '[') || 1 === preg_match('/^array\s*\(/i', $expression)) {
            return [false, true];
        }

        return $this->attributeOption($attribute, 'needsIsSandboxed', 4, false);
    }

    /** @return array{bool, bool} */
    private function attributeOption(PhpAttribute $attribute, string $name, int $position, bool $default): array
    {
        $argument = $attribute->argument($name);
        if (null === $argument) {
            $positional = $attribute->positionalArgument($position);
            $argument = null === $positional?->name ? $positional : null;
        }
        if (null === $argument) {
            return [$default, true];
        }

        return match (strtolower(trim((string) $argument->expression))) {
            'true' => [true, true],
            'false' => [false, true],
            'null' => [$default, true],
            default => [$default, false],
        };
    }

    private function objectOption(string $options, string $name): bool
    {
        return 1 === preg_match('/([\'\"])'.preg_quote($name, '/').'\\1\s*=>\s*true\b/i', $options);
    }

    private function objectKind(PhpObjectCreation $creation): ?TwigCallableKind
    {
        return match ([$creation->className, $creation->enclosingMethod]) {
            ['Twig\TwigFilter', 'getFilters'] => TwigCallableKind::Filter,
            ['Twig\TwigFunction', 'getFunctions'] => TwigCallableKind::Function,
            default => null,
        };
    }

    private function attributeKind(PhpAttribute $attribute): ?TwigCallableKind
    {
        return match ($attribute->name) {
            'Twig\Attribute\AsTwigFilter' => TwigCallableKind::Filter,
            'Twig\Attribute\AsTwigFunction' => TwigCallableKind::Function,
            default => null,
        };
    }
}
