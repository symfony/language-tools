<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectConfigurationSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly ConfigurationIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'configuration';
    }

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $section = \is_array($sections) ? ($sections['configuration'] ?? null) : null;
        if (!\is_array($section) || !\is_array($section['bundles'] ?? null)) {
            return;
        }
        $roots = [];
        foreach ($section['bundles'] as $bundle) {
            if (!\is_array($bundle) || !\is_string($bundle['alias'] ?? null) || !\is_array($bundle['tree'] ?? null)) {
                continue;
            }
            $roots[$bundle['alias']] = $this->node($bundle['tree']);
        }
        $this->indexes->forProject($project)->replace($roots);
    }

    /** @param array<array-key, mixed> $data */
    private function node(array $data): ConfigurationNode
    {
        $children = [];
        foreach (\is_array($data['children'] ?? null) ? $data['children'] : [] as $child) {
            if (\is_array($child)) {
                $children[] = $this->node($child);
            }
        }
        $prototype = \is_array($data['prototype'] ?? null) ? $this->node($data['prototype']) : null;
        $allowed = [];
        foreach (\is_array($data['allowedValues'] ?? null) ? $data['allowedValues'] : [] as $value) {
            if (null === $value || \is_scalar($value)) {
                $allowed[] = $value;
            }
        }

        return new ConfigurationNode(
            \is_string($data['name'] ?? null) ? $data['name'] : '',
            \is_string($data['type'] ?? null) ? $data['type'] : 'variable',
            true === ($data['required'] ?? false),
            true === ($data['hasDefault'] ?? false),
            \is_string($data['defaultSummary'] ?? null) ? $data['defaultSummary'] : null,
            \is_string($data['info'] ?? null) ? $data['info'] : null,
            $data['example'] ?? null,
            true === ($data['deprecated'] ?? false),
            $allowed,
            $children,
            $prototype,
        );
    }
}
