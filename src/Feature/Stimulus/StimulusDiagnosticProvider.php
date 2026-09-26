<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class StimulusDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly StimulusIndexRegistry $indexes,
        private readonly StimulusSourceIndexRegistry $sourceIndexes,
        private readonly StimulusResolver $stimulus,
    ) {
    }

    public function name(): string
    {
        return 'stimulus';
    }

    public function diagnostics(DocumentRequest $request): ?array
    {
        if ('twig' !== $request->document->languageId) {
            return null;
        }
        if (!$this->indexes->forProject($request->project)->isComplete()) {
            return [];
        }
        $known = array_fill_keys($this->stimulus->controllerNames($request->project), true);
        $facts = $this->sourceIndexes->forProject($request->project)->factsForUri($request->document->uri);
        $diagnostics = [];
        foreach ($facts instanceof StimulusSourceFacts ? $facts->references : [] as $reference) {
            if (null !== $reference->kind || isset($known[$reference->controller])) {
                continue;
            }
            $diagnostics[] = $this->protocol->diagnostic($reference->range, 1, 'stimulus.unknown_controller', \sprintf('Unknown Stimulus controller "%s".', $reference->controller));
        }

        return $diagnostics;
    }
}
