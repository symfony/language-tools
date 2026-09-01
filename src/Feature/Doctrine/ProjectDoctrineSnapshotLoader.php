<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectDoctrineSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly DoctrineIndexRegistry $indexes,
        private readonly ContainerPathMapper $pathMapper,
        private readonly UriToPathConverter $uriConverter,
    ) {
    }

    public function section(): string
    {
        return 'doctrine';
    }

    public function load(Project $project, array $section): void
    {
        $range = new Range(new Position(0, 0), new Position(0, 0));
        $entities = [];
        foreach (\is_array($section['entities'] ?? null) ? $section['entities'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['className'] ?? null) || !\is_string($item['file'] ?? null)) {
                continue;
            }
            $uri = $this->uriConverter->toUri($this->pathMapper->toHost($project, $item['file']));
            $fields = [];
            foreach (\is_array($item['fields'] ?? null) ? $item['fields'] : [] as $field) {
                if (!\is_array($field) || !\is_string($field['name'] ?? null)) {
                    continue;
                }
                $fields[] = new DoctrineField(
                    $field['name'],
                    $uri,
                    $range,
                    true === ($field['association'] ?? false),
                    \is_string($field['type'] ?? null) ? $field['type'] : null,
                    \is_string($field['targetEntity'] ?? null) ? $field['targetEntity'] : null,
                );
            }
            $entities[] = new DoctrineEntity(
                $item['className'],
                $uri,
                $range,
                \is_string($item['repositoryClass'] ?? null) ? $item['repositoryClass'] : null,
                $fields,
            );
        }
        $this->indexes->forProject($project)->replaceRuntime(...$entities);
    }
}
