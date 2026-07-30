<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class PhpClassDeclarationExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
    ) {
    }

    /** @return list<PhpClassDeclaration> */
    public function extract(string $uri, string $text): array
    {
        $namespace = '';
        if (preg_match('/\bnamespace\s+([^;{]+)[;{]/', $text, $namespaceMatch)) {
            $namespace = trim($namespaceMatch[1]);
        }

        preg_match_all(
            '/\b(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/',
            $text,
            $matches,
            \PREG_OFFSET_CAPTURE,
        );
        $declarations = [];
        foreach ($matches[1] as [$name, $offset]) {
            $declarations[] = new PhpClassDeclaration(
                '' !== $namespace ? $namespace.'\\'.$name : $name,
                $uri,
                new Range(
                    $this->positionConverter->toPosition($text, $offset),
                    $this->positionConverter->toPosition($text, $offset + \strlen($name)),
                ),
            );
        }

        return $declarations;
    }
}
