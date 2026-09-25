<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectTranslationSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly TranslationIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'translations';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $messages = [];
        foreach ($section->items('items', 'key', 'domain', 'locale', 'message') as $item) {
            $messages[] = new TranslationMessage(
                $item->string('key'),
                $item->string('domain'),
                $item->string('locale'),
                $item->string('message'),
                $item->bool('icu'),
            );
        }
        $this->indexes->forProject($project)->replaceRuntime($section->complete(), ...$messages);
    }
}
