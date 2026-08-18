<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectTwigComponentSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly TwigComponentIndexRegistry $indexes,
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
        $directory = \is_string($section['anonymousTemplateDirectory'] ?? null) && '' !== $section['anonymousTemplateDirectory']
            ? $section['anonymousTemplateDirectory']
            : 'components';
        $this->indexes->forProject($project)->replaceRuntime(
            true === ($section['complete'] ?? null),
            $names,
            $directory,
        );
    }
}
