<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\CodeActionRequest;

final class StimulusCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly StimulusIndexRegistry $indexes,
        private readonly StimulusSourceIndexRegistry $sourceIndexes,
        private readonly StimulusResolver $stimulus,
        private readonly ProjectPathResolver $paths,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(CodeActionRequest $request): array
    {
        if ('twig' !== $request->document->languageId
            || !$this->paths->isApplicationOwned($request->project, $request->document->uri)
            || !$this->indexes->forProject($request->project)->isComplete()
        ) {
            return [];
        }
        $facts = $this->sourceIndexes->forProject($request->project)->factsForUri($request->document->uri);
        $names = null;

        return $this->unknownNames->actions(
            $request,
            ['stimulus.unknown_controller'],
            $facts instanceof StimulusSourceFacts ? $facts->references : [],
            function (StimulusReference $reference) use ($request, &$names): ?array {
                if (null !== $reference->kind) {
                    return null;
                }
                $names ??= $this->stimulus->controllerNames($request->project);

                return \in_array($reference->controller, $names, true) ? null : [$reference->controller, $names];
            },
        );
    }
}
