<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Index\SourceIndexStoreInterface;
use Symfony\Lsp\Project\Project;

final class InMemorySourceIndexStore implements SourceIndexStoreInterface
{
    /** @var array<string, array<string, array{size: int, modifiedAt: int, hash: string, languageId: string, runtimeStructure: ?string, providers: array<string, string>}>> */
    private array $entries = [];

    public function load(Project $project): array
    {
        return $this->entries[$project->rootPath()] ?? [];
    }

    public function save(Project $project, array $entries): void
    {
        $this->entries[$project->rootPath()] = $entries;
    }
}
