<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectEnvironmentSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly EnvironmentIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'environment';
    }

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $section = \is_array($sections) ? ($sections['environment'] ?? null) : null;
        if (!\is_array($section) || !\is_array($section['processors'] ?? null)) {
            return;
        }
        $processors = [];
        foreach ($section['processors'] as $processor) {
            if (\is_array($processor) && \is_string($processor['name'] ?? null) && \is_string($processor['type'] ?? null)) {
                $processors[$processor['name']] = $processor['type'];
            }
        }
        $this->indexes->forProject($project)->replaceProcessors($processors, true === ($section['complete'] ?? false));
    }
}
