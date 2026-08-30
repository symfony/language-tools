<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteDocumentLinkHandler implements DocumentLinkProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly LspProtocolMapper $protocol,
        private readonly RouteDeclarationIndexRegistry $declarationIndexes,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly RouteReferenceExtractor $phpReferenceExtractor,
        private readonly TwigRouteReferenceExtractor $twigReferenceExtractor,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, target: string, tooltip: string}>|null
     */
    public function links(array $params): ?array
    {
        $request = $this->documentContextResolver->resolveDocument($params);
        if (null === $request || !\in_array($request->document->languageId, ['php', 'twig'], true)) {
            return null;
        }

        $references = 'twig' === $request->document->languageId
            ? $this->twigReferenceExtractor->extract($request->document->text)
            : $this->phpReferenceExtractor->extract($request->document->text, $this->classIndexes->forProject($request->project));
        $links = [];
        foreach ($references as $reference) {
            $declarations = $this->declarationIndexes->forProject($request->project)->find($reference->name());
            if (1 !== \count($declarations)) {
                continue;
            }

            $declaration = $declarations[0];
            $links[] = [
                'range' => $this->protocol->range($reference->range()),
                'target' => $declaration->uri().'#L'.($declaration->range()->start->line + 1),
                'tooltip' => \sprintf('Open route "%s"', $reference->name()),
            ];
        }

        return $links;
    }
}
