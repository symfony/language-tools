<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class PhpTargetAttributeResolver
{
    private const TARGET = 'Symfony\\Component\\DependencyInjection\\Attribute\\Target';

    public function __construct(private readonly PhpParserInterface $parser)
    {
    }

    public function resolve(string $text, int $cursor): ?PhpTargetArgument
    {
        $document = $this->parser->parse($text);
        foreach ($document->attributesNamed(self::TARGET) as $attribute) {
            $argument = $this->nameArgument($attribute);
            $start = $argument?->expressionStartOffset;
            $end = $argument?->expressionEndOffset;
            if (null === $argument || !\is_int($start) || !\is_int($end) || $cursor <= $start || $cursor > $end) {
                continue;
            }

            $value = substr($text, $start, $cursor - $start);
            if (!\in_array($value[0], ['\'', '"'], true)) {
                return null;
            }

            $value = substr($value, 1);
            if (preg_match('/[\'"\r\n]/', $value)) {
                return null;
            }

            [$types, $parameterName] = $this->parameter($document, $attribute);
            $literal = $argument->stringLiteral;

            return new PhpTargetArgument(
                $start + 1,
                $value,
                $literal?->startOffset,
                $literal?->endOffset,
                $literal?->value,
                $types,
                $parameterName,
            );
        }

        return null;
    }

    public function resolveLiteral(string $text, int $offset): ?PhpTargetArgument
    {
        $document = $this->parser->parse($text);
        foreach ($document->attributesNamed(self::TARGET) as $attribute) {
            $literal = $this->nameArgument($attribute)?->stringLiteral;
            if (null === $literal || $offset < $literal->startOffset || $offset > $literal->endOffset) {
                continue;
            }

            [$types, $parameterName] = $this->parameter($document, $attribute);

            return new PhpTargetArgument(
                $literal->startOffset,
                substr($text, $literal->startOffset, $offset - $literal->startOffset),
                $literal->startOffset,
                $literal->endOffset,
                $literal->value,
                $types,
                $parameterName,
            );
        }

        return null;
    }

    private function nameArgument(PhpAttribute $attribute): ?PhpArgument
    {
        $argument = $attribute->namedOrPositionalArgument('name', 0);

        return null === $argument || $argument->unpacked ? null : $argument;
    }

    /** @return array{list<string>, ?string} */
    private function parameter(PhpDocument $document, PhpAttribute $attribute): array
    {
        foreach ($document->methodDeclarations as $method) {
            foreach ($method->parameters as $parameter) {
                foreach ($parameter->attributes as $candidate) {
                    if (self::TARGET === $candidate->name && $attribute->startOffset === $candidate->startOffset) {
                        return [$parameter->types, $parameter->name];
                    }
                }
            }
        }

        return [[], null];
    }
}
