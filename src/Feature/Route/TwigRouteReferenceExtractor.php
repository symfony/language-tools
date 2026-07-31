<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigDocument;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigRouteReferenceExtractor
{
    private readonly TwigDocumentParser $parser;

    public function __construct(
        private readonly PositionConverter $positionConverter,
        ?TwigDocumentParser $parser = null,
    ) {
        $this->parser = $parser ?? new TwigDocumentParser(new NativeTreeSitterParser());
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
            $argumentsNode = $document->directChild($call, 'arguments');
            if (null === $argumentsNode) {
                continue;
            }
            $arguments = [];
            foreach ($document->children($argumentsNode) as $argument) {
                if ('argument' === $argument->type()) {
                    $arguments[] = $argument;
                }
            }
            $route = isset($arguments[0]) ? $document->literalString($arguments[0]) : null;
            if (null === $route) {
                continue;
            }

            $references[] = new RouteReference(
                $route[0],
                new Range(
                    $this->positionConverter->toPosition($text, $route[1]),
                    $this->positionConverter->toPosition($text, $route[2]),
                ),
                $this->providedParameters($document, $arguments[1] ?? null),
            );
        }

        return $references;
    }

    public function at(string $text, int $byteOffset): ?RouteReference
    {
        foreach ($this->extract($text) as $reference) {
            $start = $this->positionConverter->toByteOffset($text, $reference->range()->start());
            $end = $this->positionConverter->toByteOffset($text, $reference->range()->end());
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
        if (null === $hash) {
            return null;
        }

        $parameters = [];
        foreach ($document->children($hash) as $key) {
            if ('hash_key' !== $key->type()) {
                continue;
            }
            $string = $document->firstString($key);
            if (null !== $string && null !== $literal = $document->string($string)) {
                $parameters[] = $literal[0];
                continue;
            }
            if (null !== $name = $document->firstDescendant($key, 'name')) {
                $parameters[] = $document->text($name);
            }
        }

        return array_values(array_unique($parameters));
    }
}
