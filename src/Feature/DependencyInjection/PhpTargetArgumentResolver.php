<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class PhpTargetArgumentResolver
{
    private const TARGET = 'Symfony\\Component\\DependencyInjection\\Attribute\\Target';

    public function __construct(private readonly PhpParserInterface $parser)
    {
    }

    public function resolve(string $text, int $cursor): ?PhpTargetArgument
    {
        foreach ($this->parser->parse($text)->methodDeclarations as $method) {
            foreach ($method->parameters as $parameter) {
                foreach ($parameter->attributes as $attribute) {
                    if (self::TARGET !== $attribute->name) {
                        continue;
                    }

                    $literal = $attribute->namedOrPositionalArgument('name', 0)?->stringLiteral;
                    if (null === $literal || $cursor < $literal->startOffset || $cursor > $literal->endOffset) {
                        continue;
                    }

                    if (1 !== \count($parameter->types)) {
                        return null;
                    }

                    return new PhpTargetArgument(
                        $parameter->types[0],
                        $literal->value,
                        $literal->startOffset,
                        substr($text, $literal->startOffset, $cursor - $literal->startOffset),
                    );
                }
            }
        }

        return null;
    }
}
