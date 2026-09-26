<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class StimulusDocumentLinkProvider implements DocumentLinkProviderInterface
{
    public function __construct(
        private readonly UriToPathConverter $uriConverter,
        private readonly LspProtocolMapper $protocol,
        private readonly StimulusIndexRegistry $indexes,
        private readonly StimulusExtractor $extractor,
        private readonly StimulusResolver $stimulus,
    ) {
    }

    public function links(DocumentRequest $request): array
    {
        if ('twig' !== $request->document->languageId) {
            return [];
        }
        $links = [];
        foreach ($this->extractor->extract($request->project, $request->source)->references as $reference) {
            $locations = $this->stimulus->declarationLocations($request->project, $reference);
            $target = $locations[0]['uri'] ?? null;
            if (!\is_string($target)) {
                $controller = $this->indexes->forProject($request->project)->controller($reference->controller);
                $target = null === $controller ? null : $this->uriConverter->toUri($controller->sourcePath);
            }
            if (null !== $target) {
                $links[] = $this->protocol->documentLink($reference->range, $target);
            }
        }

        return $links;
    }
}
