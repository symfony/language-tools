<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigDocument;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigRouteReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly TwigDocumentParser $parser,
        private readonly TwigCallArgumentResolver $arguments,
    ) {
    }

    /** @return list<RouteReference> */
    public function extract(string $text): array
    {
        $document = $this->parser->parse($text);
        $references = [];
        foreach ($document->nodesOfType('function_call') as $call) {
            $function = $document->directChild($call, 'function_identifier');
            if (null === $function || !\in_array($document->text($function), ['path', 'url'], true)) {
                continue;
            }
            $arguments = $this->arguments->resolve($document, $call);
            $routeArgument = $arguments->get(0, 'name');
            $route = null === $routeArgument ? null : $document->soleStringLiteral($routeArgument);
            if (null === $route) {
                continue;
            }

            $references[] = new RouteReference(
                $route->value,
                new Range(
                    $this->positionConverter->toPosition($text, $route->startOffset),
                    $this->positionConverter->toPosition($text, $route->endOffset),
                ),
                $this->providedParameters($document, $arguments->get(1, 'parameters')),
            );
        }

        return $references;
    }

    public function at(string $text, int $byteOffset): ?RouteReference
    {
        foreach ($this->extract($text) as $reference) {
            $start = $this->positionConverter->toByteOffset($text, $reference->range->start);
            $end = $this->positionConverter->toByteOffset($text, $reference->range->end);
            if ($byteOffset >= $start && $byteOffset <= $end) {
                return $reference;
            }
        }

        return null;
    }

    /** @return list<string>|null */
    private function providedParameters(TwigDocument $document, ?TreeSitterNode $argument): ?array
    {
        if (null === $argument) {
            return [];
        }
        $value = trim($document->text($argument));
        if (!str_starts_with($value, '{') || !str_ends_with($value, '}')) {
            return null;
        }
        $hash = $document->firstDescendant($argument, 'hash');
        if (null === $hash || $hash->hasError) {
            return null;
        }

        $parameters = [];
        $explicitValue = false;
        foreach ($document->children($hash) as $child) {
            if ('hash_key' === $child->type) {
                $parameter = $this->hashKey($document, $child);
                if ($explicitValue || null === $parameter) {
                    return null;
                }
                $parameters[] = $parameter;
                $explicitValue = true;

                continue;
            }
            if ('hash_value' !== $child->type) {
                return null;
            }
            if ($explicitValue) {
                $explicitValue = false;

                continue;
            }
            $parameter = trim($document->text($child));
            if (1 !== preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/D', $parameter)) {
                return null;
            }
            $parameters[] = $parameter;
        }

        return $explicitValue ? null : array_values(array_unique($parameters));
    }

    private function hashKey(TwigDocument $document, TreeSitterNode $key): ?string
    {
        $children = $document->children($key);
        if (1 !== \count($children)) {
            return null;
        }
        $key = $children[0];
        if (null !== $literal = $document->stringLiteral($key)) {
            return $literal->value;
        }

        return \in_array($key->type, ['name', 'number'], true) ? $document->text($key) : null;
    }
}
