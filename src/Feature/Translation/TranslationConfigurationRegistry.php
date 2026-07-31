<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Project\Project;

final class TranslationConfigurationRegistry
{
    /** @var array<string, bool> */
    private array $missingKeyDiagnostics = [];

    public function configure(Project $project, bool $enabled): void
    {
        $this->missingKeyDiagnostics[$project->rootPath()] = $enabled;
    }

    public function missingKeyDiagnostics(Project $project): bool
    {
        return $this->missingKeyDiagnostics[$project->rootPath()] ?? false;
    }
}
