<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectServiceSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly ServiceIndexRegistry $serviceIndexes,
        private readonly ParameterIndexRegistry $parameterIndexes,
    ) {
    }

    public function section(): string
    {
        return 'container';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $services = [];
        foreach ($section->items('items', 'id') as $item) {
            $services[] = new Service(
                $item->string('id'),
                $item->optionalString('class'),
                $item->optionalString('alias'),
                $item->optionalBool('public'),
                $item->optionalBool('lazy'),
                $item->optionalString('deprecation'),
                $item->strings('tags'),
                $item->optionalString('decorates'),
                $item->strings('autowiringTypes'),
                $item->strings('decorationStack'),
            );
        }
        $this->serviceIndexes->forProject($project)->replace($section->complete('services'), ...$services);

        $parameters = [];
        foreach ($section->items('parameters', 'name') as $item) {
            $parameters[] = new Parameter($item->string('name'), $item->optionalString('deprecation'));
        }
        $this->parameterIndexes->forProject($project)->replace($section->complete('parameters'), ...$parameters);
    }
}
