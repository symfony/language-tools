<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectStimulusSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly StimulusIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'stimulus';
    }

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $section = \is_array($sections) ? ($sections['stimulus'] ?? null) : null;
        if (!\is_array($section)) {
            return;
        }
        $controllers = [];
        foreach (\is_array($section['controllers'] ?? null) ? $section['controllers'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['name'] ?? null) || !\is_string($item['sourcePath'] ?? null)) {
                continue;
            }
            $controllers[] = new StimulusController(
                $item['name'],
                $item['sourcePath'],
                true === ($item['lazy'] ?? false),
                true === ($item['vendor'] ?? false),
                $this->strings($item['actions'] ?? []),
                $this->strings($item['targets'] ?? []),
                $this->strings($item['values'] ?? []),
                $this->strings($item['outlets'] ?? []),
                $this->strings($item['classes'] ?? []),
            );
        }
        $this->indexes->forProject($project)->replace(true === ($section['complete'] ?? false), ...$controllers);
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }
        $values = array_values(array_filter($values, 'is_string'));
        sort($values);

        return $values;
    }
}
