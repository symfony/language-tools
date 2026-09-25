<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectTwigComponentSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly UriToPathConverter $uriConverter,
    ) {
    }

    public function section(): string
    {
        return 'twig_components';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $range = new Range(new Position(0, 0), new Position(0, 0));
        $components = [];
        foreach ($section->items('components', 'name', 'file') as $item) {
            $components[] = new TwigComponent(
                $item->string('name'),
                $this->uriConverter->toUri($item->path('file')),
                $range,
                $item->optionalString('class'),
                $item->optionalString('template'),
                live: $item->bool('live'),
            );
        }
        $this->indexes->forProject($project)->replaceRuntime(
            $section->complete(),
            $section->enabled(),
            $section->strings('names'),
            $section->string('anonymousTemplateDirectory') ?: 'components',
            $section->strings('caseInsensitiveNames'),
            $components,
        );
    }
}
