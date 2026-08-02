<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TemplateReferenceExtractor
{
    public function __construct(private readonly PositionConverter $positionConverter, private readonly TwigDocumentParser $twigParser)
    {
    }

    /** @return list<TemplateReference> */
    public function extract(string $uri, string $languageId, string $text): array
    {
        if ('twig' === $languageId) {
            return $this->twigReferences($uri, $text);
        }
        if ('php' !== $languageId) {
            return [];
        }

        preg_match_all(
            '/(?:->|::)(?:render|renderView)\s*\(\s*([\'"])([^\'"]+)\1\s*(?:,\s*\[([^\]]*)\])?/',
            $text,
            $matches,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL,
        );
        $references = [];
        foreach ($matches as $match) {
            $name = $match[2][0] ?? null;
            $offset = $match[2][1];
            if (!\is_string($name)) {
                continue;
            }
            $variables = [];
            if (\is_string($match[3][0] ?? null)) {
                preg_match_all('/([\'"])([^\'"]+)\1\s*=>/', $match[3][0], $keys);
                $variables = array_values(array_unique($keys[2]));
            }
            $references[] = $this->reference($name, $uri, $text, $offset, $variables);
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

    /** @return list<TemplateReference> */
    private function twigReferences(string $uri, string $text): array
    {
        $document = $this->twigParser->parse($text);
        $references = [];
        foreach ($document->nodesOfType('tag_statement') as $statement) {
            $tag = $document->directChild($statement, 'tag');
            $target = $document->directString($statement);
            if (null === $tag || null === $target || !\in_array($document->text($tag), ['embed', 'extends', 'from', 'import', 'include', 'use'], true)) {
                continue;
            }
            if (null !== $literal = $document->string($target)) {
                $references[] = $this->reference($literal[0], $uri, $text, $literal[1]);
            }
        }
        foreach ($document->nodesOfType('function_call') as $call) {
            $name = $document->directChild($call, 'function_identifier');
            if (null === $name || !\in_array($document->text($name), ['include', 'source'], true)) {
                continue;
            }
            $arguments = $document->directChild($call, 'arguments');
            $argument = null === $arguments ? null : $document->directChild($arguments, 'argument');
            $literal = null === $argument ? null : $document->literalString($argument);
            if (null !== $literal) {
                $references[] = $this->reference($literal[0], $uri, $text, $literal[1]);
            }
        }
        usort($references, static fn (TemplateReference $left, TemplateReference $right): int => $left->range()->start()->line() <=> $right->range()->start()->line() ?: $left->range()->start()->character() <=> $right->range()->start()->character());

        return $references;
    }

    /** @param list<string> $variables */
    private function reference(string $name, string $uri, string $text, int $offset, array $variables = []): TemplateReference
    {
        return new TemplateReference(
            $name,
            $uri,
            new Range(
                $this->positionConverter->toPosition($text, $offset),
                $this->positionConverter->toPosition($text, $offset + \strlen($name)),
            ),
            $variables,
        );
    }
}
