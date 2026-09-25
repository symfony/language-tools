<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectMetadataSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly MetadataIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'metadata';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $forms = [];
        foreach ($section->items('forms', 'class') as $item) {
            $forms[] = new FormType(
                $item->string('class'),
                $item->optionalString('blockPrefix'),
                $item->strings('options'),
                $item->strings('requiredOptions'),
            );
        }
        $constraints = [];
        foreach ($section->items('constraints', 'name', 'class') as $item) {
            $constraints[] = new ValidationConstraint(
                $item->string('name'),
                $item->string('class'),
                $item->strings('options'),
            );
        }
        $this->indexes->forProject($project)->replace(
            $forms,
            $constraints,
            $section->complete('forms'),
            $section->complete('constraints'),
        );
    }
}
