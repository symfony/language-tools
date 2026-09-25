<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectConfigurationSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly ConfigurationIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'configuration';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $roots = [];
        foreach ($section->items('bundles', 'alias') as $bundle) {
            $tree = $bundle->section('tree');
            if (null !== $tree) {
                $roots[$bundle->string('alias')] = $this->node($tree);
            }
        }
        $this->indexes->forProject($project)->replace($roots);
    }

    private function node(SnapshotSection $data, ?string $entryKeyAttribute = null): ConfigurationNode
    {
        $children = [];
        foreach ($data->items('children') as $child) {
            $children[] = $this->node($child);
        }
        $keyAttribute = $data->optionalString('keyAttribute');
        $prototype = $data->section('prototype');

        return new ConfigurationNode(
            $data->string('name'),
            $data->optionalString('type') ?? 'variable',
            $data->bool('required'),
            $data->bool('hasDefault'),
            $data->optionalString('defaultSummary'),
            $data->optionalString('info'),
            $data->value('example'),
            $data->bool('deprecated'),
            $data->scalars('allowedValues'),
            $data->strings('allowedEnumCases'),
            $children,
            null === $prototype ? null : $this->node($prototype, $keyAttribute),
            $data->boolMap('accepts'),
            $data->stringMap('aliases'),
            $keyAttribute,
            $entryKeyAttribute,
            $data->bool('normalizeKeys', true),
            $data->bool('allowedValuesTruncated'),
        );
    }
}
