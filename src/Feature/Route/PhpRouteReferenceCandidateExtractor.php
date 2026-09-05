<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;

final class PhpRouteReferenceCandidateExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly RoutePhpReceiverResolver $receivers,
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
            $receiver = $this->receivers->resolve($document, $call);
            if (null === $receiver) {
                continue;
            }

            $references[] = new RouteReference(
                $name->value,
                new Range(
                    $this->positionConverter->toPosition($text, $name->startOffset),
                    $this->positionConverter->toPosition($text, $name->endOffset),
                ),
                $this->providedParameters($document, $call, $route),
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
    private function providedParameters(PhpDocument $document, PhpMethodCall $call, PhpArgument $route): ?array
    {
        $parameters = $call->arguments[1] ?? null;
        if (null === $parameters) {
            return null === $route->completeLiteral ? null : [];
        }
        $array = $document->literalArray($parameters);
        if (null !== $parameters->name || $parameters->unpacked || null === $array || $array->hasUnknownKeys) {
            return null;
        }

        return array_values(array_unique(array_map(static fn (PhpStringLiteral $key): string => $key->value, $array->keys)));
    }
}
