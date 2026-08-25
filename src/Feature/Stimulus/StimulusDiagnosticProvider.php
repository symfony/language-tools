<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class StimulusDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly StimulusIndexRegistry $indexes,
        private readonly StimulusExtractor $extractor,
        private readonly StimulusResolver $stimulus,
    ) {
    }

    public function name(): string
    {
        return 'stimulus';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        if (null === $request || 'twig' !== $request->document->languageId()) {
            return null;
        }
        if (!$this->indexes->forProject($request->project)->isComplete()) {
            return [];
        }
        $known = array_fill_keys($this->stimulus->controllerNames($request->project), true);
        $diagnostics = [];
        foreach ($this->extractor->extract($request->project, $request->document->uri(), $request->document->languageId(), $request->document->text())->references() as $reference) {
            if (null !== $reference->kind() || isset($known[$reference->controller()])) {
                continue;
            }
            $diagnostics[] = $this->protocol->diagnostic($reference->range(), 1, 'stimulus.unknown_controller', \sprintf('Unknown Stimulus controller "%s".', $reference->controller()));
        }

        return $diagnostics;
    }
}
