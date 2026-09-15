<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class PhpRouteDeclarationExtractor
{
    private const ROUTING_CONFIGURATOR = 'Symfony\\Component\\Routing\\Loader\\Configurator\\RoutingConfigurator';
    private const ROUTE_COLLECTION = 'Symfony\\Component\\Routing\\RouteCollection';

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
            $collectionVariables ??= $this->collectionVariableOffsets($document, $source->text);
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
    private function collectionVariableOffsets(PhpDocument $document, string $text): array
    {
        $offsets = [];
        foreach ($document->typedVariables as $variable) {
            if (\in_array(self::ROUTING_CONFIGURATOR, $variable->types, true)) {
                $offsets[$variable->name] ??= $variable->nameStartOffset;
            }
        }
        foreach ($document->objectCreations as $creation) {
            if (self::ROUTE_COLLECTION !== $creation->className) {
                continue;
            }
            $before = substr($text, 0, $creation->startOffset);
            $statement = substr($before, max((int) strrpos($before, ';'), (int) strrpos($before, '{')));
            if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*$/D', $statement, $assignment)) {
                $offsets[$assignment[1]] ??= $creation->startOffset;
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
