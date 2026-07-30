<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;

final class RouteHoverHandler
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly PositionConverter $positionConverter,
        private readonly RouteIndexRegistry $routeIndexes,
        private readonly TwigRouteReferenceExtractor $twigReferenceExtractor,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{contents: array{kind: string, value: string}}|null
     */
    public function hover(array $params): ?array
    {
        $request = $this->documentContextResolver->resolve($params);
        if (null === $request) {
            return null;
        }

        [$document, $project, $position] = $request;
        if (!\in_array($document->languageId(), ['php', 'twig'], true)) {
            return null;
        }

        $offset = $this->positionConverter->toByteOffset($document->text(), $position);
        $reference = 'twig' === $document->languageId()
            ? $this->twigReferenceExtractor->at($document->text(), $offset)
            : (new RouteReferenceExtractor($this->positionConverter))->at($document->text(), $offset);
        if (null === $reference || null === $route = $this->routeIndexes->forProject($project)->get($reference->name())) {
            return null;
        }

        $details = [\sprintf('`%s`', $route->name())];
        if (null !== $route->path()) {
            $details[] = \sprintf('Path: `%s`', $route->path());
        }
        if ([] !== $route->methods()) {
            $details[] = \sprintf('Methods: `%s`', implode('`, `', $route->methods()));
        }
        if (null !== $route->controller()) {
            $details[] = \sprintf('Controller: `%s`', $route->controller());
        }

        return ['contents' => ['kind' => 'markdown', 'value' => implode("\n\n", $details)]];
    }
}
