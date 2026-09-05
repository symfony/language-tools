<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;
use Symfony\Lsp\Parser\Php\PhpLiteralKind;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;

final class PhpRouteReferenceCandidateExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly RoutePhpReceiverResolver $receivers,
        private readonly PhpLiteralArrayKeyParser $arrayKeys,
    ) {
    }

    /**
     * @return list<RouteReference>
     */
    public function extract(string $text, PhpDocument $document): array
    {
        $references = [];
        foreach ($document->methodCalls as $call) {
            $route = $call->positionalArgument(0);
            if (null === $route || null === $name = $route->stringLiteral) {
                continue;
            }
            $receiver = $this->receivers->resolve($text, $document, $call);
            if (null === $receiver) {
                continue;
            }

            $references[] = new RouteReference(
                $name->value,
                new Range(
                    $this->positionConverter->toPosition($text, $name->startOffset),
                    $this->positionConverter->toPosition($text, $name->endOffset),
                ),
                $this->providedParameters($call, $route),
                $receiver->controllerClass,
            );
        }

        return $references;
    }

    /**
     * Literal parameter keys, or null when the call cannot be read statically.
     *
     * @return list<string>|null
     */
    private function providedParameters(PhpMethodCall $call, PhpArgument $route): ?array
    {
        if (null === $route->completeLiteral) {
            return null;
        }
        $parameters = $call->arguments[1] ?? null;
        if (null === $parameters) {
            return [];
        }
        if (null !== $parameters->name
            || $parameters->unpacked
            || PhpLiteralKind::Array !== $parameters->completeLiteral?->kind
            || null === $keys = $this->arrayKeys->parseArgument($parameters, allowNestedUnpacking: true)
        ) {
            return null;
        }

        return array_values(array_unique(array_map(static fn (PhpStringLiteral $key): string => $key->value, $keys)));
    }
}
