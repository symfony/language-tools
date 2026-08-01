<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
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
    public function extract(string $uri, string $text): array
    {
        $declarations = [];
        $document = $this->parser->parse($text);
        foreach ($document->attributes() as $attribute) {
            if (!\in_array($attribute->name(), [
                'Symfony\Component\Routing\Annotation\Route',
                'Symfony\Component\Routing\Attribute\Route',
            ], true)) {
                continue;
            }

            $name = ($attribute->argument('name') ?? $attribute->argument(1))?->stringLiteral();
            if (null === $name || '' === $name->value()) {
                continue;
            }

            $declarations[] = $this->declaration(
                $name->value(),
                $uri,
                $text,
                $name->startOffset(),
                $name->endOffset(),
            );
        }

        foreach ($document->methodCalls() as $call) {
            if ('add' !== $call->method() || !preg_match('/^\$(\w+)$/', $call->receiver(), $variable)) {
                continue;
            }
            $beforeCall = substr($text, 0, $call->startOffset());
            if (!preg_match(
                '/(?:RoutingConfigurator\s+\$'.preg_quote($variable[1], '/').'\b|\$'.preg_quote($variable[1], '/').'\s*=\s*new\s+(?:\\\\?RouteCollection|[^\s;(]*\\\\RouteCollection)\b)/s',
                $beforeCall,
            )) {
                continue;
            }
            $name = $call->argument(0)?->stringLiteral();
            if (null === $name || '' === $name->value()) {
                continue;
            }

            $declarations[] = $this->declaration(
                $name->value(),
                $uri,
                $text,
                $name->startOffset(),
                $name->endOffset(),
            );
        }

        usort(
            $declarations,
            static fn (RouteDeclaration $left, RouteDeclaration $right): int => $left->range()->start()->line() <=> $right->range()->start()->line()
                ?: $left->range()->start()->character() <=> $right->range()->start()->character(),
        );

        return $declarations;
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
