<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectTranslationSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly TranslationIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'translations';
    }

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $section = \is_array($sections) ? ($sections['translations'] ?? null) : null;
        if (!\is_array($section) || !\is_array($section['items'] ?? null)) {
            return;
        }
        $messages = [];
        foreach ($section['items'] as $item) {
            if (\is_array($item) && \is_string($item['key'] ?? null) && \is_string($item['domain'] ?? null) && \is_string($item['locale'] ?? null) && \is_string($item['message'] ?? null)) {
                $messages[] = new TranslationMessage($item['key'], $item['domain'], $item['locale'], $item['message']);
            }
        }
        $this->indexes->forProject($project)->replaceRuntime(true === ($section['complete'] ?? null), ...$messages);
    }
}
