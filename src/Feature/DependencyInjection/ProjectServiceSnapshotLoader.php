<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotValues;

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

    public function load(Project $project, array $section): void
    {
        $items = $section['items'] ?? null;
        if (!\is_array($items)) {
            return;
        }

        $services = [];
        foreach ($items as $item) {
            if (!\is_array($item) || !\is_string($item['id'] ?? null)) {
                continue;
            }

            $services[] = new Service(
                $item['id'],
                \is_string($item['class'] ?? null) ? $item['class'] : null,
                \is_string($item['alias'] ?? null) ? $item['alias'] : null,
                \is_bool($item['public'] ?? null) ? $item['public'] : null,
                \is_bool($item['lazy'] ?? null) ? $item['lazy'] : null,
                \is_string($item['deprecation'] ?? null) ? $item['deprecation'] : null,
                RuntimeSnapshotValues::stringList($item['tags'] ?? null),
                \is_string($item['decorates'] ?? null) ? $item['decorates'] : null,
                RuntimeSnapshotValues::stringList($item['autowiringTypes'] ?? null),
                RuntimeSnapshotValues::stringList($item['decorationStack'] ?? null),
            );
        }

        $servicesComplete = true === ($section['servicesComplete'] ?? null);
        $this->serviceIndexes->forProject($project)->replace($servicesComplete, ...$services);

        $parameters = [];
        foreach (\is_array($section['parameters'] ?? null) ? $section['parameters'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['name'] ?? null)) {
                continue;
            }

            $parameters[] = new Parameter(
                $item['name'],
                \is_string($item['deprecation'] ?? null) ? $item['deprecation'] : null,
            );
        }
        $parametersComplete = true === ($section['parametersComplete'] ?? $section['complete'] ?? null);
        $this->parameterIndexes->forProject($project)->replace($parametersComplete, ...$parameters);
    }
}
