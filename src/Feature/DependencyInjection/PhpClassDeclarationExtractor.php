<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class PhpClassDeclarationExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly PhpParserInterface $parser,
    ) {
    }

    /** @return list<PhpClassDeclaration> */
    public function extract(string $uri, string $text): array
    {
        $declarations = [];
        foreach ($this->parser->parse($text)->typeDeclarations as $type) {
            $declarations[] = new PhpClassDeclaration(
                $type->name,
                $uri,
                new Range(
                    $this->positionConverter->toPosition($text, $type->nameStartOffset),
                    $this->positionConverter->toPosition($text, $type->nameEndOffset),
                ),
                $type->parentClassName,
            );
        }

        return $declarations;
    }
}
