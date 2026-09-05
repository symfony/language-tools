<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpLiteralKind;
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

    public function extract(SourceDocument $document): TwigCallableSourceFacts
    {
        $declarations = [];
        $methods = [];
        $php = $this->parser->parse($document->text);
        foreach ($php->objectCreations as $creation) {
            $kind = $this->objectKind($creation);
            if (null === $kind) {
                continue;
            }
            $name = $this->literalName($creation->namedOrPositionalArgument('name', 0)?->stringLiteral);
            if (null === $name) {
                continue;
            }
            $callable = $creation->namedOrPositionalArgument('callable', 1)?->callable;
            $options = $this->objectOptions($creation);
            $declarations[] = new TwigCallableDeclaration(
                kind: $kind,
                name: $name->value,
                uri: $document->uri,
                range: new Range(
                    $this->converter->toPosition($document->text, $name->startOffset),
                    $this->converter->toPosition($document->text, $name->endOffset),
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

        foreach ($php->methodDeclarations as $method) {
            if (!$method->public) {
                continue;
            }
            foreach ($method->attributes as $attribute) {
                $kind = $this->attributeKind($attribute);
                if (null === $kind) {
                    continue;
                }
                $name = $this->literalName($attribute->namedOrPositionalArgument('name', 0)?->stringLiteral);
                if (null === $name) {
                    continue;
                }
                $options = $this->attributeOptions($attribute, $method);
                $declarations[] = new TwigCallableDeclaration(
                    kind: $kind,
                    name: $name->value,
                    uri: $document->uri,
                    range: new Range(
                        $this->converter->toPosition($document->text, $name->startOffset),
                        $this->converter->toPosition($document->text, $name->endOffset),
                    ),
                    className: $method->className,
                    method: $method->name,
                    needsEnvironment: $options['needsEnvironment'],
                    needsContext: $options['needsContext'],
                    variadic: array_any($method->parameters, static fn ($parameter): bool => $parameter->variadic),
                    optionsKnown: $options['known'],
                    needsCharset: $options['needsCharset'],
                    needsIsSandboxed: $options['needsIsSandboxed'],
                );
            }
        }

        $targets = [];
        foreach ($declarations as $declaration) {
            if (null !== $declaration->className && null !== $declaration->method) {
                $targets[TwigCallableKey::from($declaration->className, $declaration->method)] = true;
            }
        }
        foreach ($php->methodDeclarations as $method) {
            if (!$method->public || !isset($targets[TwigCallableKey::from($method->className, $method->name)])) {
                continue;
            }
            $methods[] = new TwigCallableSourceMethod(
                $method->className,
                $method->name,
                array_map(
                    static fn ($parameter): TwigCallableMethodParameter => new TwigCallableMethodParameter($parameter->name, $parameter->types, $parameter->variadic),
                    $method->parameters,
                ),
                [] === $php->diagnostics,
            );
        }

        return new TwigCallableSourceFacts($document->uri, $declarations, methods: $methods);
    }

    private function literalName(?PhpStringLiteral $name): ?PhpStringLiteral
    {
        return null === $name || '' === $name->value || str_contains($name->value, '\\') ? null : $name;
    }

    /** @return array{needsCharset: bool, needsEnvironment: bool, needsContext: bool, needsIsSandboxed: bool, variadic: bool, known: bool} */
    private function objectOptions(PhpObjectCreation $creation): array
    {
        $argument = $creation->namedOrPositionalArgument('options', 2);
        if (null === $argument) {
            return ['needsCharset' => false, 'needsEnvironment' => false, 'needsContext' => false, 'needsIsSandboxed' => false, 'variadic' => false, 'known' => true];
        }
        $expression = trim((string) $argument->expression);
        $known = PhpLiteralKind::Array === $argument->completeLiteral?->kind;

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
        $firstParameter = $method->parameters[0] ?? null;
        [$needsEnvironment, $environmentKnown] = $this->attributeOption(
            $attribute,
            'needsEnvironment',
            2,
            null !== $firstParameter && ['Twig\Environment'] === $firstParameter->types && !$firstParameter->variadic,
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
        $argument = $attribute->namedOrPositionalArgument('needsIsSandboxed', 4);
        if (null === $argument) {
            return [false, true];
        }
        if (null === $argument->name && PhpLiteralKind::Array === $argument->completeLiteral?->kind) {
            return [false, true];
        }

        return $this->attributeOption($attribute, 'needsIsSandboxed', 4, false);
    }

    /** @return array{bool, bool} */
    private function attributeOption(PhpAttribute $attribute, string $name, int $position, bool $default): array
    {
        $argument = $attribute->namedOrPositionalArgument($name, $position);
        if (null === $argument) {
            return [$default, true];
        }

        return match ($argument->completeLiteral?->kind) {
            PhpLiteralKind::Boolean => [true === $argument->completeLiteral->scalarValue, true],
            PhpLiteralKind::Null => [$default, true],
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
