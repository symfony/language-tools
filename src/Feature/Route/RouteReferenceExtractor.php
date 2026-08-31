<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class RouteReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly PhpParserInterface $parser,
        private readonly PhpRouteReferenceCandidateExtractor $candidates,
        private readonly RouteControllerClassifier $controllers,
    ) {
    }

    /**
     * @return list<RouteReference>
     */
    public function extract(string $text, ?DependencyInjectionSourceIndex $classIndex = null): array
    {
        $document = $this->parser->parse($text);

        return array_values(array_filter(
            $this->candidates->extract($text, $document),
            fn (RouteReference $reference): bool => $this->controllers->isController($reference->controllerClass, $document, $classIndex),
        ));
    }

    /**
     * @return list<RouteReference>
     */
    public function extractCandidates(string $text): array
    {
        $document = $this->parser->parse($text);

        return $this->candidates->extract($text, $document);
    }

    public function isSymfonyReceiver(string $source, DependencyInjectionSourceIndex $classIndex): bool
    {
        $document = $this->parser->parse($source);
        $receiver = $this->candidates->resolveReceiver($source, \strlen($source), $document);

        return null !== $receiver && $this->controllers->isController($receiver->controllerClass, $document, $classIndex);
    }

    public function at(string $text, int $byteOffset, ?DependencyInjectionSourceIndex $classIndex = null): ?RouteReference
    {
        foreach ($this->extract($text, $classIndex) as $reference) {
            $start = $this->positionConverter->toByteOffset($text, $reference->range->start);
            $end = $this->positionConverter->toByteOffset($text, $reference->range->end);
            if ($byteOffset >= $start && $byteOffset <= $end) {
                return $reference;
            }
        }

        return null;
    }
}
