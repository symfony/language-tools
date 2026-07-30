<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;

final class RouteCompletionHandler implements CompletionProviderInterface
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
     * @return list<array{label: string, kind: int, detail: string, textEdit: array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}}>|null
     */
    public function complete(array $params): ?array
    {
        $request = $this->documentContextResolver->resolve($params);
        if (null === $request) {
            return null;
        }

        [$document, $project, $position] = $request;
        if (!\in_array($document->languageId(), ['php', 'twig'], true)) {
            return null;
        }

        $routeIndex = $this->routeIndexes->forProject($project);
        if ('twig' === $document->languageId()) {
            $parameterContext = TwigRouteParameterCompletionContext::fromTwig(
                $document->text(),
                $position,
                $this->positionConverter,
            );
            if (null !== $parameterContext) {
                $route = $routeIndex->get($parameterContext->routeName());
                if (null === $route) {
                    return [];
                }

                return $this->withTextEdits(
                    $this->completeParameters(
                        $route,
                        $parameterContext->prefix(),
                        $parameterContext->existingParameters(),
                    ),
                    $parameterContext->replacementRange(),
                );
            }

            $routeContext = TwigRouteCompletionContext::fromTwig(
                $document->text(),
                $position,
                $this->positionConverter,
            );
            if (null === $routeContext) {
                return null;
            }

            return $this->withTextEdits(
                (new RouteCompletionProvider($routeIndex))->complete($routeContext->prefix()),
                $routeContext->replacementRange(),
            );
        }
        $parameterContext = RouteParameterCompletionContext::fromPhp(
            $document->text(),
            $position,
            $this->positionConverter,
        );
        if (null !== $parameterContext) {
            $route = $routeIndex->get($parameterContext->routeName());
            if (null === $route) {
                return [];
            }

            return $this->withTextEdits(
                $this->completeParameters(
                    $route,
                    $parameterContext->prefix(),
                    $parameterContext->existingParameters(),
                ),
                $parameterContext->replacementRange(),
            );
        }

        $routeContext = RouteCompletionContext::fromPhp(
            $document->text(),
            $position,
            $this->positionConverter,
        );
        if (null === $routeContext) {
            return null;
        }

        return $this->withTextEdits(
            (new RouteCompletionProvider($routeIndex))->complete($routeContext->prefix()),
            $routeContext->replacementRange(),
        );
    }

    /**
     * @param list<string> $existingParameters
     *
     * @return list<array{label: string, kind: int, detail: string}>
     */
    private function completeParameters(Route $route, string $prefix, array $existingParameters): array
    {
        return array_map(
            static fn (string $parameter): array => [
                'label' => $parameter,
                'kind' => 10,
                'detail' => \sprintf('Parameter of route %s', $route->name()),
            ],
            array_values(array_filter(
                $route->parameters(),
                static fn (string $parameter): bool => str_starts_with($parameter, $prefix)
                    && !\in_array($parameter, $existingParameters, true),
            )),
        );
    }

    /**
     * @param list<array{label: string, kind: int, detail: string}> $items
     *
     * @return list<array{label: string, kind: int, detail: string, textEdit: array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}}>
     */
    private function withTextEdits(array $items, Range $range): array
    {
        return array_map(
            static fn (array $item): array => [
                ...$item,
                'textEdit' => [
                    'range' => [
                        'start' => [
                            'line' => $range->start()->line(),
                            'character' => $range->start()->character(),
                        ],
                        'end' => [
                            'line' => $range->end()->line(),
                            'character' => $range->end()->character(),
                        ],
                    ],
                    'newText' => $item['label'],
                ],
            ],
            $items,
        );
    }
}
