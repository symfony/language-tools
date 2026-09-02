<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotValues;

final class ProjectMetadataSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly MetadataIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'metadata';
    }

    public function load(Project $project, array $section): void
    {
        $forms = [];
        foreach (\is_array($section['forms'] ?? null) ? $section['forms'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['class'] ?? null)) {
                continue;
            }
            $forms[] = new FormType(
                $item['class'],
                \is_string($item['blockPrefix'] ?? null) ? $item['blockPrefix'] : null,
                RuntimeSnapshotValues::stringList($item['options'] ?? null),
                RuntimeSnapshotValues::stringList($item['requiredOptions'] ?? null),
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
                RuntimeSnapshotValues::stringList($item['options'] ?? null),
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
