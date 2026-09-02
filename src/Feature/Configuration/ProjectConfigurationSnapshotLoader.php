<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotValues;

final class ProjectConfigurationSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly ConfigurationIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'configuration';
    }

    public function load(Project $project, array $section): void
    {
        if (!\is_array($section['bundles'] ?? null)) {
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
    private function node(array $data, ?string $entryKeyAttribute = null): ConfigurationNode
    {
        $children = [];
        foreach (\is_array($data['children'] ?? null) ? $data['children'] : [] as $child) {
            if (\is_array($child)) {
                $children[] = $this->node($child);
            }
        }
        $keyAttribute = \is_string($data['keyAttribute'] ?? null) ? $data['keyAttribute'] : null;
        $prototype = \is_array($data['prototype'] ?? null) ? $this->node($data['prototype'], $keyAttribute) : null;
        $allowed = [];
        foreach (\is_array($data['allowedValues'] ?? null) ? $data['allowedValues'] : [] as $value) {
            if (null === $value || \is_scalar($value)) {
                $allowed[] = $value;
            }
        }
        $allowedEnumCases = RuntimeSnapshotValues::stringList($data['allowedEnumCases'] ?? null);
        $accepts = [];
        foreach (\is_array($data['accepts'] ?? null) ? $data['accepts'] : [] as $kind => $accepted) {
            if (\is_string($kind) && \is_bool($accepted)) {
                $accepts[$kind] = $accepted;
            }
        }
        $aliases = [];
        foreach (\is_array($data['aliases'] ?? null) ? $data['aliases'] : [] as $alias => $name) {
            if (\is_string($alias) && \is_string($name)) {
                $aliases[$alias] = $name;
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
            $allowedEnumCases,
            $children,
            $prototype,
            $accepts,
            $aliases,
            $keyAttribute,
            $entryKeyAttribute,
            false !== ($data['normalizeKeys'] ?? true),
            true === ($data['allowedValuesTruncated'] ?? false),
        );
    }
}
