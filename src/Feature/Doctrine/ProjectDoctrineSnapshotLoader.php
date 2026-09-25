<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectDoctrineSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly DoctrineIndexRegistry $indexes,
        private readonly UriToPathConverter $uriConverter,
    ) {
    }

    public function section(): string
    {
        return 'doctrine';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $range = new Range(new Position(0, 0), new Position(0, 0));
        $entities = [];
        foreach ($section->items('entities', 'className', 'file') as $item) {
            $uri = $this->uriConverter->toUri($item->path('file'));
            $fields = [];
            foreach ($item->items('fields', 'name') as $field) {
                $fields[] = new DoctrineField(
                    $field->string('name'),
                    $uri,
                    $range,
                    $field->bool('association'),
                    $field->optionalString('type'),
                    $field->optionalString('targetEntity'),
                );
            }
            $entities[] = new DoctrineEntity(
                $item->string('className'),
                $uri,
                $range,
                $item->optionalString('repositoryClass'),
                $fields,
            );
        }
        $this->indexes->forProject($project)->replaceRuntime(...$entities);
    }
}
