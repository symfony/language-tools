<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class StimulusCodeLensProvider implements CodeLensProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly StimulusSourceIndexRegistry $sourceIndexes,
        private readonly StimulusExtractor $extractor,
    ) {
    }

    public function codeLenses(DocumentRequest $request): array
    {
        if (!\in_array($request->document->languageId, ['javascript', 'typescript'], true)) {
            return [];
        }
        $lenses = [];
        foreach ($this->extractor->extract($request->project, $request->source)->declarations as $declaration) {
            $locations = [];
            foreach ($this->sourceIndexes->forProject($request->project)->references($declaration->name) as $reference) {
                $locations[] = $this->protocol->location($reference->uri, $reference->range);
            }
            $count = \count($locations);
            $lenses[] = $this->protocol->referenceLens($declaration->range, \sprintf('%d Stimulus controller usage%s', $count, 1 === $count ? '' : 's'), $declaration->uri, $locations);
        }

        return $lenses;
    }
}
