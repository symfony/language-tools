<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\Twig\TemplateIndexRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteDiagnosticPublisher implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly LspProtocolMapper $protocol,
        private readonly RouteIndexRegistry $routeIndexes,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly RouteReferenceExtractor $phpReferenceExtractor,
        private readonly TwigRouteReferenceExtractor $twigReferenceExtractor,
        private readonly TemplateIndexRegistry $templateIndexes,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function diagnostics(array $params): ?array
    {
        $request = $this->documentContextResolver->resolveDocument($params);
        if (null === $request || !\in_array($request->document->languageId(), ['php', 'twig'], true)) {
            return null;
        }

        if ('twig' === $request->document->languageId()
            && !$this->templateIndexes->forProject($request->project)->isRuntimeTemplateUri($request->document->uri())
        ) {
            return [];
        }
        $routeIndex = $this->routeIndexes->forProject($request->project);
        if (!$routeIndex->isComplete()) {
            return null;
        }

        $diagnostics = [];
        $references = 'twig' === $request->document->languageId()
            ? $this->twigReferenceExtractor->extract($request->document->text())
            : $this->phpReferenceExtractor->extract($request->document->text(), $this->classIndexes->forProject($request->project));
        foreach ($references as $reference) {
            $route = $routeIndex->get($reference->name());
            if (null === $route) {
                $diagnostics[] = $this->protocol->diagnostic($reference->range(), 1, 'route.not_found', \sprintf('Route "%s" does not exist in the selected environment.', $reference->name()));

                continue;
            }

            if (null === $reference->providedParameters()) {
                continue;
            }

            $missingParameters = array_values(array_diff(
                $route->requiredParameters(),
                $reference->providedParameters(),
            ));
            if ([] !== $missingParameters) {
                $diagnostics[] = $this->protocol->diagnostic(
                    $reference->range(),
                    1,
                    'route.missing_parameters',
                    \sprintf(
                        'Route "%s" requires parameter%s "%s".',
                        $reference->name(),
                        1 === \count($missingParameters) ? '' : 's',
                        implode('", "', $missingParameters),
                    ),
                );
            }
        }

        return $diagnostics;
    }
}
