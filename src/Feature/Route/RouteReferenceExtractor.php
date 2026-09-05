<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

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
            $this->candidates->extract($source->text, $document),
            fn (RouteReference $reference): bool => $this->controllers->isController($reference->controllerClass, $document, $classIndex),
        ));
    }

    /**
     * @return list<RouteReference>
     */
    public function extractCandidates(SourceDocument $source): array
    {
        $document = $this->parser->parse($source->text);

        return $this->candidates->extract($source->text, $document);
    }

    public function supportsRouteCallAt(string $source, int $byteOffset, ?DependencyInjectionSourceIndex $classIndex = null): bool
    {
        $document = $this->parser->parse($source);
        $call = null;
        foreach ($document->methodCalls as $candidate) {
            if (!\in_array($candidate->method, RoutePhpMethods::ALL, true)
                || $candidate->startOffset > $byteOffset
                || $candidate->endOffset < $byteOffset
            ) {
                continue;
            }
            if (null === $call || $candidate->startOffset > $call->startOffset) {
                $call = $candidate;
            }
        }
        $receiver = null === $call ? null : $this->receivers->resolve($source, $document, $call);

        return null !== $receiver && $this->controllers->isController($receiver->controllerClass, $document, $classIndex);
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
