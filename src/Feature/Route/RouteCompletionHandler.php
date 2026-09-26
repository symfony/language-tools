<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteCompletionHandler implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly PositionConverter $positionConverter,
        private readonly LspProtocolMapper $protocol,
        private readonly RouteIndexRegistry $routeIndexes,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly RouteReferenceExtractor $phpReferenceExtractor,
        private readonly CommentParserRegistry $comments,
        private readonly RouteCompletionBuilder $completionBuilder,
        private readonly TwigDirectiveLocator $directives,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array{label: string, kind: int, detail: string, textEdit: array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}}>|null
     */
    public function complete(array $params): ?array
    {
        $request = $this->documentContextResolver->resolvePositioned($params);
        if (null === $request || !\in_array($request->document->languageId, ['php', 'twig'], true)) {
            return null;
        }

        $routeIndex = $this->routeIndexes->forProject($request->project);
        if ('twig' === $request->document->languageId) {
            $twigText = $this->comments->mask($request->document->languageId, $request->document->text);
            if (!$this->directives->insideDirective($twigText, $this->positionConverter->toByteOffset($twigText, $request->position))) {
                return null;
            }
            $parameterContext = TwigRouteParameterCompletionContext::fromTwig(
                $twigText,
                $request->position,
                $this->positionConverter,
            );
            if (null !== $parameterContext) {
                $route = $routeIndex->get($parameterContext->routeName);
                if (null === $route) {
                    return [];
                }

                return $this->withTextEdits(
                    $this->completeParameters(
                        $route,
                        $parameterContext->prefix,
                        $parameterContext->existingParameters,
                    ),
                    $parameterContext->replacementRange,
                );
            }

            $routeContext = TwigRouteCompletionContext::fromTwig(
                $twigText,
                $request->position,
                $this->positionConverter,
            );
            if (null === $routeContext) {
                return null;
            }

            return $this->withTextEdits(
                $this->completionBuilder->complete($routeIndex, $routeContext->prefix),
                $routeContext->replacementRange,
            );
        }
        $context = $this->phpReferenceExtractor->phpCompletionAt(
            $request->document->text,
            $this->positionConverter->toByteOffset($request->document->text, $request->position),
            $this->classIndexes->forProject($request->project),
        );
        if ($context instanceof RouteParameterCompletionContext) {
            $route = $routeIndex->get($context->routeName);
            if (null === $route) {
                return [];
            }

            return $this->withTextEdits(
                $this->completeParameters($route, $context->prefix, $context->existingParameters),
                $context->replacementRange,
            );
        }
        if (null === $context) {
            return null;
        }

        return $this->withTextEdits(
            $this->completionBuilder->complete($routeIndex, $context->prefix),
            $context->replacementRange,
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
                'detail' => \sprintf('Parameter of route %s', $route->name),
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
            fn (array $item): array => [
                ...$item,
                'textEdit' => $this->protocol->textEdit($range, $item['label']),
            ],
            $items,
        );
    }
}
