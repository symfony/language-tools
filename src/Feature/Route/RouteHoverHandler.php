<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteHoverHandler implements HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly PositionConverter $positionConverter,
        private readonly LspProtocolMapper $protocol,
        private readonly RouteIndexRegistry $routeIndexes,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly RouteReferenceExtractor $phpReferenceExtractor,
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
        $request = $this->documentContextResolver->resolvePositioned($params);
        if (null === $request || !\in_array($request->document->languageId, ['php', 'twig'], true)) {
            return null;
        }

        $offset = $this->positionConverter->toByteOffset($request->document->text, $request->position);
        $document = SourceDocument::fromDocument($request->document);
        $reference = 'twig' === $request->document->languageId
            ? $this->twigReferenceExtractor->at($document, $offset)
            : $this->phpReferenceExtractor->at($document, $offset, $this->classIndexes->forProject($request->project));
        if (null === $reference || null === $route = $this->routeIndexes->forProject($request->project)->get($reference->name)) {
            return null;
        }

        $details = [\sprintf('`%s`', $route->name)];
        if (null !== $route->alias) {
            $details[] = \sprintf('Alias of: `%s`', $route->alias);
        }
        if (null !== $route->path) {
            $details[] = \sprintf('Path: `%s`', $route->path);
        }
        if (null !== $route->host) {
            $details[] = \sprintf('Host: `%s`', $route->host);
        }
        if ([] !== $route->methods) {
            $details[] = \sprintf('Methods: `%s`', implode('`, `', $route->methods));
        }
        if ([] !== $route->schemes) {
            $details[] = \sprintf('Schemes: `%s`', implode('`, `', $route->schemes));
        }
        if ([] !== $route->defaults) {
            $details[] = \sprintf('Defaults: `%s`', implode('`, `', $route->defaults));
        }
        if ([] !== $route->requirements) {
            $requirements = [];
            foreach ($route->requirements as $name => $requirement) {
                $requirements[] = $name.': '.$requirement;
            }
            $details[] = \sprintf('Requirements: `%s`', implode('`, `', $requirements));
        }
        if (null !== $route->controller) {
            $details[] = \sprintf('Controller: `%s`', $route->controller);
        }

        return $this->protocol->markdownHover(implode("\n\n", $details));
    }
}
