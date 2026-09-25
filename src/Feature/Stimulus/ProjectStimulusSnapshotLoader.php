<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectStimulusSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly StimulusIndexRegistry $indexes,
        private readonly StimulusControllerSourceLoader $sources,
    ) {
    }

    public function section(): string
    {
        return 'stimulus';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $controllers = [];
        foreach ($section->items('controllers', 'name', 'sourcePath') as $item) {
            $sourcePath = $item->path('sourcePath');
            $source = $this->sources->load($sourcePath);
            $controllers[] = new StimulusController(
                $item->string('name'),
                $sourcePath,
                $item->optionalBool('lazy') ?? (bool) $source?->lazy,
                $item->bool('vendor'),
                $source?->memberNames(StimulusMemberKind::Action) ?? [],
                $source?->memberNames(StimulusMemberKind::Target) ?? [],
                $source?->memberNames(StimulusMemberKind::Value) ?? [],
                $source?->memberNames(StimulusMemberKind::Outlet) ?? [],
                $source?->memberNames(StimulusMemberKind::ClassName) ?? [],
            );
        }
        $this->indexes->forProject($project)->replace($section->complete(), ...$controllers);
    }
}
