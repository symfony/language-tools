<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectMetadataSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly MetadataIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'metadata';
    }

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $section = \is_array($sections) ? ($sections['metadata'] ?? null) : null;
        if (!\is_array($section)) {
            return;
        }
        $forms = [];
        foreach (\is_array($section['forms'] ?? null) ? $section['forms'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['class'] ?? null)) {
                continue;
            }
            $forms[] = new FormType(
                $item['class'],
                \is_string($item['blockPrefix'] ?? null) ? $item['blockPrefix'] : null,
                array_values(array_filter(\is_array($item['options'] ?? null) ? $item['options'] : [], 'is_string')),
                array_values(array_filter(\is_array($item['requiredOptions'] ?? null) ? $item['requiredOptions'] : [], 'is_string')),
            );
        }
        $constraints = [];
        foreach (\is_array($section['constraints'] ?? null) ? $section['constraints'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['name'] ?? null) || !\is_string($item['class'] ?? null)) {
                continue;
            }
            $constraints[] = new ValidationConstraint(
                $item['name'],
                $item['class'],
                array_values(array_filter(\is_array($item['options'] ?? null) ? $item['options'] : [], 'is_string')),
            );
        }
        $this->indexes->forProject($project)->replace(
            $forms,
            $constraints,
            true === ($section['formsComplete'] ?? false),
            true === ($section['constraintsComplete'] ?? false),
        );
    }
}
