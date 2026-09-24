<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class StimulusCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly StimulusIndexRegistry $indexes,
        private readonly StimulusSourceIndexRegistry $sourceIndexes,
        private readonly StimulusResolver $stimulus,
        private readonly ProjectPathResolver $paths,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        $context = $params['context'] ?? null;
        if (null === $request || !\is_array($context) || 'twig' !== $request->document->languageId
            || !$this->paths->isApplicationOwned($request->project, $request->document->uri)
        ) {
            return null;
        }
        if (!$this->indexes->forProject($request->project)->isComplete()) {
            return [];
        }
        $facts = $this->sourceIndexes->forProject($request->project)->factsForUri($request->document->uri);
        $names = null;
        $actions = [];
        foreach (\is_array($context['diagnostics'] ?? null) ? $context['diagnostics'] : [] as $diagnostic) {
            if (!\is_array($diagnostic) || 'stimulus.unknown_controller' !== ($diagnostic['code'] ?? null) || !\is_array($diagnostic['range'] ?? null)) {
                continue;
            }
            foreach ($facts instanceof StimulusSourceFacts ? $facts->references : [] as $reference) {
                if (null !== $reference->kind || !$this->protocol->sameRange($reference->range, $diagnostic['range'])) {
                    continue;
                }
                $names ??= $this->stimulus->controllerNames($request->project);
                if (\in_array($reference->controller, $names, true)) {
                    break;
                }
                array_push($actions, ...$this->unknownNames->replacements(
                    $request->document,
                    $diagnostic,
                    $reference->range,
                    $reference->controller,
                    $names,
                ));
                break;
            }
        }

        return $actions;
    }
}
