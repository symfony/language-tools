<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class StimulusDocumentLinkProvider implements DocumentLinkProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly UriToPathConverter $uriConverter,
        private readonly LspProtocolMapper $protocol,
        private readonly StimulusIndexRegistry $indexes,
        private readonly StimulusExtractor $extractor,
        private readonly StimulusResolver $stimulus,
    ) {
    }

    public function links(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        if (null === $request || 'twig' !== $request->document->languageId) {
            return null;
        }
        $links = [];
        foreach ($this->extractor->extract($request->project, $request->document->uri, $request->document->languageId, $request->document->text)->references as $reference) {
            $locations = $this->stimulus->declarationLocations($request->project, $reference);
            $target = $locations[0]['uri'] ?? null;
            if (!\is_string($target)) {
                $controller = $this->indexes->forProject($request->project)->controller($reference->controller);
                $target = null === $controller ? null : $this->uriConverter->toUri($controller->sourcePath);
            }
            if (null !== $target) {
                $links[] = ['range' => $this->protocol->range($reference->range), 'target' => $target];
            }
        }

        return $links;
    }
}
