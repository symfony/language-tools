<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigComponentCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TemplateIndexRegistry $templates,
        private readonly TwigComponentResolver $components,
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
        $index = $this->indexes->forProject($request->project);
        if (!$index->isComplete() || !$index->isRuntimeComplete() || !$index->isRuntimeEnabled()
            || !$this->templates->forProject($request->project)->isComplete()
        ) {
            return [];
        }
        $facts = $index->factsForUri($request->document->uri);
        $names = null;
        $actions = [];
        foreach (\is_array($context['diagnostics'] ?? null) ? $context['diagnostics'] : [] as $diagnostic) {
            if (!\is_array($diagnostic) || 'twig_component.not_found' !== ($diagnostic['code'] ?? null) || !\is_array($diagnostic['range'] ?? null)) {
                continue;
            }
            foreach ($facts instanceof TwigComponentSourceFacts ? $facts->references : [] as $reference) {
                if (!$this->protocol->sameRange($reference->range, $diagnostic['range'])
                    || null !== $index->get($reference->name)
                    || $index->hasRuntimeName($reference->name)
                    || $this->components->anonymousTemplateExists($request->project, $reference->name)
                ) {
                    continue;
                }
                $names ??= [
                    ...$index->runtimeNames(),
                    ...array_map(static fn (TwigComponent $component): string => $component->name, $index->components()),
                    ...$this->components->anonymousComponentNames($request->project),
                ];
                array_push($actions, ...$this->unknownNames->replacements($request->document, $diagnostic, $reference->range, $reference->name, $names));
                break;
            }
        }

        return $actions;
    }
}
