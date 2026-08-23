<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectTwigComponentSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly ContainerPathMapper $pathMapper,
        private readonly UriToPathConverter $uriConverter,
    ) {
    }

    public function section(): string
    {
        return 'twig_components';
    }

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $section = \is_array($sections) ? ($sections['twig_components'] ?? null) : null;
        if (!\is_array($section) || !\is_array($section['names'] ?? null)) {
            return;
        }
        $names = array_values(array_filter($section['names'], 'is_string'));
        $caseInsensitiveNames = array_values(array_filter(
            \is_array($section['caseInsensitiveNames'] ?? null) ? $section['caseInsensitiveNames'] : [],
            'is_string',
        ));
        $directory = \is_string($section['anonymousTemplateDirectory'] ?? null) && '' !== $section['anonymousTemplateDirectory']
            ? $section['anonymousTemplateDirectory']
            : 'components';
        $range = new Range(new Position(0, 0), new Position(0, 0));
        $components = [];
        foreach (\is_array($section['components'] ?? null) ? $section['components'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['name'] ?? null) || !\is_string($item['file'] ?? null)) {
                continue;
            }
            $components[] = new TwigComponent(
                $item['name'],
                $this->uriConverter->toUri($this->pathMapper->toHost($project, $item['file'])),
                $range,
                \is_string($item['class'] ?? null) ? $item['class'] : null,
                \is_string($item['template'] ?? null) ? $item['template'] : null,
                live: true === ($item['live'] ?? false),
            );
        }
        $this->indexes->forProject($project)->replaceRuntime(
            true === ($section['complete'] ?? null),
            $names,
            $directory,
            $caseInsensitiveNames,
            $components,
        );
    }
}
