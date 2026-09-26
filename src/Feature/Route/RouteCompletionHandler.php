<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Protocol\CompletionItemKind;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;

final class RouteCompletionHandler implements CompletionProviderInterface
{
    public function __construct(
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

    /** @return list<array<array-key, mixed>> */
    public function complete(PositionedRequest $request): array
    {
        if (!\in_array($request->document->languageId, ['php', 'twig'], true)) {
            return [];
        }

        $routeIndex = $this->routeIndexes->forProject($request->project);
        if ('twig' === $request->document->languageId) {
            $twigText = $this->comments->mask($request->document->languageId, $request->document->text);
            if (!$this->directives->insideDirective($twigText, $request->offset)) {
                return [];
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
                return [];
            }

            return $this->withTextEdits(
                $this->completionBuilder->complete($routeIndex, $routeContext->prefix),
                $routeContext->replacementRange,
            );
        }
        $context = $this->phpReferenceExtractor->phpCompletionAt(
            $request->document->text,
            $request->offset,
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
            return [];
        }

        return $this->withTextEdits(
            $this->completionBuilder->complete($routeIndex, $context->prefix),
            $context->replacementRange,
        );
    }

    /**
     * @param list<string> $existingParameters
     *
     * @return list<array<array-key, mixed>>
     */
    private function completeParameters(Route $route, string $prefix, array $existingParameters): array
    {
        return array_map(
            fn (string $parameter): array => $this->protocol->completionItem($parameter, CompletionItemKind::Property, \sprintf('Parameter of route %s', $route->name)),
            array_values(array_filter(
                $route->parameters(),
                static fn (string $parameter): bool => str_starts_with($parameter, $prefix)
                    && !\in_array($parameter, $existingParameters, true),
            )),
        );
    }

    /**
     * @param list<array<array-key, mixed>> $items
     *
     * @return list<array<array-key, mixed>>
     */
    private function withTextEdits(array $items, Range $range): array
    {
        return array_map(
            fn (array $item): array => [
                ...$item,
                'textEdit' => $this->protocol->textEdit($range, \is_string($item['label'] ?? null) ? $item['label'] : ''),
            ],
            $items,
        );
    }
}
