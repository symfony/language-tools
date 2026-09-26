<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteDocumentLinkHandler implements DocumentLinkProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly RouteSourceIndexRegistry $sourceIndexes,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly RouteReferenceExtractor $phpReferenceExtractor,
        private readonly TwigRouteReferenceExtractor $twigReferenceExtractor,
    ) {
    }

    public function links(DocumentRequest $request): array
    {
        if (!\in_array($request->document->languageId, ['php', 'twig'], true)) {
            return [];
        }

        $references = 'twig' === $request->document->languageId
            ? $this->twigReferenceExtractor->extract($request->source)
            : $this->phpReferenceExtractor->extract($request->source, $this->classIndexes->forProject($request->project));
        $links = [];
        foreach ($references as $reference) {
            $declarations = $this->sourceIndexes->forProject($request->project)->declarations($reference->name);
            if (1 !== \count($declarations)) {
                continue;
            }

            $declaration = $declarations[0];
            $links[] = $this->protocol->documentLink(
                $reference->range,
                $declaration->uri.'#L'.($declaration->range->start->line + 1),
                \sprintf('Open route "%s"', $reference->name),
            );
        }

        return $links;
    }
}
