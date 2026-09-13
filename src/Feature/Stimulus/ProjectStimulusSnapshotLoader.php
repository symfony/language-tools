<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectStimulusSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly StimulusIndexRegistry $indexes,
        private readonly ContainerPathMapper $pathMapper,
        private readonly StimulusControllerSourceLoader $sources,
    ) {
    }

    public function section(): string
    {
        return 'stimulus';
    }

    public function load(Project $project, array $section): void
    {
        $controllers = [];
        foreach (\is_array($section['controllers'] ?? null) ? $section['controllers'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['name'] ?? null) || !\is_string($item['sourcePath'] ?? null)) {
                continue;
            }
            $sourcePath = $this->pathMapper->toHost($project, $item['sourcePath']);
            $source = $this->sources->load($sourcePath);
            $lazy = $item['lazy'] ?? null;
            $controllers[] = new StimulusController(
                $item['name'],
                $sourcePath,
                \is_bool($lazy) ? $lazy : (bool) $source?->lazy,
                true === ($item['vendor'] ?? false),
                $source?->memberNames(StimulusMemberKind::Action) ?? [],
                $source?->memberNames(StimulusMemberKind::Target) ?? [],
                $source?->memberNames(StimulusMemberKind::Value) ?? [],
                $source?->memberNames(StimulusMemberKind::Outlet) ?? [],
                $source?->memberNames(StimulusMemberKind::ClassName) ?? [],
            );
        }
        $this->indexes->forProject($project)->replace(true === ($section['complete'] ?? false), ...$controllers);
    }
}
