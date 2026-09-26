<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;

final class RouteReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly PhpParserInterface $parser,
        private readonly PhpRouteReferenceCandidateExtractor $candidates,
        private readonly RoutePhpReceiverResolver $receivers,
        private readonly RouteControllerClassifier $controllers,
    ) {
    }

    /**
     * @return list<RouteReference>
     */
    public function extract(SourceDocument $source, ?DependencyInjectionSourceIndex $classIndex = null): array
    {
        $document = $this->parser->parse($source->text);

        return array_values(array_filter(
            $this->candidates->extract($source, $document),
            fn (RouteReference $reference): bool => $this->controllers->isController($reference->controllerClass, $document, $classIndex),
        ));
    }

    /**
     * @return list<RouteReference>
     */
    public function extractCandidates(SourceDocument $source): array
    {
        $document = $this->parser->parse($source->text);

        return $this->candidates->extract($source, $document);
    }

    public function phpCompletionAt(string $source, int $byteOffset, ?DependencyInjectionSourceIndex $classIndex = null): RouteCompletionContext|RouteParameterCompletionContext|null
    {
        $document = $this->parser->parse($source);
        $cursor = $document->argumentCursorAt($byteOffset);
        $call = $cursor?->call;
        if (null === $cursor || !$call instanceof PhpMethodCall) {
            return null;
        }
        $receiver = $this->receivers->resolve($document, $call);
        if (null === $receiver || !$this->controllers->isController($receiver->controllerClass, $document, $classIndex)) {
            return null;
        }
        $range = new Range(
            $this->positionConverter->toPosition($source, $cursor->prefixStartOffset),
            $this->positionConverter->toPosition($source, $byteOffset),
        );
        if ($cursor->isArgumentLiteral() && $cursor->isPositional(0)) {
            return new RouteCompletionContext($cursor->prefix, $range);
        }
        $name = $call->positionalArgument(0)?->stringLiteral?->value;
        if (!$cursor->isArrayItemLiteral() || !$cursor->isPositional(1) || null === $name || '' === $name) {
            return null;
        }

        return new RouteParameterCompletionContext(
            $name,
            $cursor->prefix,
            $range,
            array_values(array_unique(array_map(
                static fn (PhpStringLiteral $key): string => $key->value,
                $document->literalArray($cursor->argument)->keys ?? [],
            ))),
        );
    }

    public function at(SourceDocument $document, int $byteOffset, ?DependencyInjectionSourceIndex $classIndex = null): ?RouteReference
    {
        foreach ($this->extract($document, $classIndex) as $reference) {
            $start = $this->positionConverter->toByteOffset($document->text, $reference->range->start);
            $end = $this->positionConverter->toByteOffset($document->text, $reference->range->end);
            if ($byteOffset >= $start && $byteOffset <= $end) {
                return $reference;
            }
        }

        return null;
    }
}
