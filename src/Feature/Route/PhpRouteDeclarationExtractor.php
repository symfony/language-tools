<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class PhpRouteDeclarationExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly PhpParserInterface $parser,
    ) {
    }

    /**
     * @return list<RouteDeclaration>
     */
    public function extract(SourceDocument $source): array
    {
        $declarations = [];
        $document = $this->parser->parse($source->text);
        foreach ($document->attributes as $attribute) {
            if (!\in_array($attribute->name, [
                'Symfony\Component\Routing\Annotation\Route',
                'Symfony\Component\Routing\Attribute\Route',
            ], true)) {
                continue;
            }

            $name = $attribute->namedOrPositionalArgument('name', 1)?->stringLiteral;
            if (null === $name || '' === $name->value) {
                continue;
            }

            $declarations[] = $this->declaration(
                $name->value,
                $source->uri,
                $source->text,
                $name->startOffset,
                $name->endOffset,
            );
        }

        $collectionVariables = null;
        foreach ($document->methodCalls as $call) {
            if ('add' !== $call->method || !preg_match('/^\$(\w+)$/', $call->receiver, $variable)) {
                continue;
            }
            $collectionVariables ??= $this->collectionVariableOffsets($source->text);
            $declaredAt = $collectionVariables[$variable[1]] ?? null;
            if (null === $declaredAt || $declaredAt > $call->startOffset) {
                continue;
            }
            $name = $call->positionalArgument(0)?->stringLiteral;
            if (null === $name || '' === $name->value) {
                continue;
            }

            $declarations[] = $this->declaration(
                $name->value,
                $source->uri,
                $source->text,
                $name->startOffset,
                $name->endOffset,
            );
        }

        usort(
            $declarations,
            static fn (RouteDeclaration $left, RouteDeclaration $right): int => $left->range->start->line <=> $right->range->start->line
                ?: $left->range->start->character <=> $right->range->start->character,
        );

        return $declarations;
    }

    /**
     * @return array<string, int> First offset at which each variable is bound to a route collection
     */
    private function collectionVariableOffsets(string $text): array
    {
        if (!preg_match_all(
            '/RoutingConfigurator\s+\$(\w+)\b|\$(\w+)\s*=\s*new\s+(?:\\\\?RouteCollection|[^\s;(]*\\\\RouteCollection)\b/s',
            $text,
            $matches,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        )) {
            return [];
        }

        $offsets = [];
        foreach ($matches as $match) {
            $variable = '' === ($match[1][0] ?? '') ? ($match[2][0] ?? '') : $match[1][0];
            if ('' !== $variable) {
                $offsets[$variable] ??= $match[0][1];
            }
        }

        return $offsets;
    }

    private function declaration(string $name, string $uri, string $text, int $offset, int $endOffset): RouteDeclaration
    {
        return new RouteDeclaration(
            $name,
            $uri,
            new Range(
                $this->positionConverter->toPosition($text, $offset),
                $this->positionConverter->toPosition($text, $endOffset),
            ),
        );
    }
}
