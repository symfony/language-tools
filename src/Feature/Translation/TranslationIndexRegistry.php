<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Project\Project;

final class TranslationIndexRegistry
{
    /** @var array<string, TranslationIndex> */
    private array $indexes = [];

    public function forProject(Project $project): TranslationIndex
    {
        return $this->indexes[$project->rootPath()] ??= new TranslationIndex();
    }
}
