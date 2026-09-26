<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigComponentCodeLensProvider implements CodeLensProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TwigComponentExtractor $extractor,
    ) {
    }

    public function codeLenses(DocumentRequest $request): array
    {
        if ('php' !== $request->document->languageId) {
            return [];
        }
        $lenses = [];
        foreach ($this->extractor->extract($request->project, $request->source)->components as $component) {
            $locations = [];
            foreach ($this->indexes->forProject($request->project)->references($component->name) as $reference) {
                $locations[] = $this->protocol->location($reference->uri, $reference->range);
            }
            $count = \count($locations);
            $lenses[] = $this->protocol->referenceLens($component->range, \sprintf('%d Twig component usage%s', $count, 1 === $count ? '' : 's'), $component->uri, $locations);
        }

        return $lenses;
    }
}
