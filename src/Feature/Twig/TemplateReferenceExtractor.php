<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class TemplateReferenceExtractor
{
    public function __construct(private readonly PositionConverter $positionConverter)
    {
    }

    /** @return list<TemplateReference> */
    public function extract(string $uri, string $languageId, string $text): array
    {
        $patterns = 'php' === $languageId ? [
            '/(?:->|::)(?:render|renderView)\s*\(\s*([\'\"])([^\'\"]+)\1/',
        ] : ('twig' === $languageId ? [
            '/{%\s*(?:extends|include|embed|import|from|use)\s+([\'\"])([^\'\"]+)\1/s',
            '/\b(?:include|source)\s*\(\s*([\'\"])([^\'\"]+)\1/s',
        ] : []);
        $references = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[2] as [$name, $offset]) {
                $references[] = new TemplateReference(
                    $name,
                    $uri,
                    new Range(
                        $this->positionConverter->toPosition($text, $offset),
                        $this->positionConverter->toPosition($text, $offset + \strlen($name)),
                    ),
                );
            }
        }

        return $references;
    }

    public function at(string $uri, string $languageId, string $text, int $offset): ?TemplateReference
    {
        foreach ($this->extract($uri, $languageId, $text) as $reference) {
            $start = $this->positionConverter->toByteOffset($text, $reference->range()->start());
            $end = $this->positionConverter->toByteOffset($text, $reference->range()->end());
            if ($offset >= $start && $offset <= $end) {
                return $reference;
            }
        }

        return null;
    }
}
