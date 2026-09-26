<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\CodeActionRequest;

final class TwigComponentCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TemplateIndexRegistry $templates,
        private readonly TwigComponentResolver $components,
        private readonly ProjectPathResolver $paths,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(CodeActionRequest $request): array
    {
        if ('twig' !== $request->document->languageId || !$this->paths->isApplicationOwned($request->project, $request->document->uri)) {
            return [];
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->hasScannedSources() || !$index->isRuntimeComplete() || !$index->isRuntimeEnabled()
            || !$this->templates->forProject($request->project)->isComplete()
        ) {
            return [];
        }
        $facts = $index->factsForUri($request->document->uri);
        $names = null;

        return $this->unknownNames->actions(
            $request,
            ['twig_component.not_found'],
            $facts instanceof TwigComponentSourceFacts ? $facts->references : [],
            function (TwigComponentReference $reference) use ($request, $index, &$names): ?array {
                if (null !== $index->get($reference->name)
                    || $index->hasRuntimeName($reference->name)
                    || $this->components->anonymousTemplateExists($request->project, $reference->name)
                ) {
                    return null;
                }
                $names ??= [
                    ...$index->runtimeNames(),
                    ...array_map(static fn (TwigComponent $component): string => $component->name, $index->components()),
                    ...$this->components->anonymousComponentNames($request->project),
                ];

                return [$reference->name, $names];
            },
        );
    }
}
