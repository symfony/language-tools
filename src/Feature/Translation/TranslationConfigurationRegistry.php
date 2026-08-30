<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectStateInterface;

final class TranslationConfigurationRegistry implements ProjectStateInterface
{
    /** @var array<string, bool> */
    private array $missingKeyDiagnostics = [];

    public function configure(Project $project, bool $enabled): void
    {
        $this->missingKeyDiagnostics[$project->rootPath] = $enabled;
    }

    public function missingKeyDiagnostics(Project $project): bool
    {
        return $this->missingKeyDiagnostics[$project->rootPath] ?? false;
    }

    public function removeProject(Project $project): void
    {
        unset($this->missingKeyDiagnostics[$project->rootPath]);
    }
}
