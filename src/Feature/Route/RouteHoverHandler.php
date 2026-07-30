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
        if ('php' !== $document->languageId()) {
            return null;
        }

        $reference = (new RouteReferenceExtractor($this->positionConverter))->at(
            $document->text(),
            $this->positionConverter->toByteOffset($document->text(), $position),
        );
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
