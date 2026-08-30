<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigComponentCodeLensProvider implements CodeLensProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TwigComponentExtractor $extractor,
    ) {
    }

    public function codeLenses(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId) {
            return null;
        }
        $lenses = [];
        foreach ($this->extractor->extract($request->project, $request->document->uri, 'php', $request->document->text)->components as $component) {
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
