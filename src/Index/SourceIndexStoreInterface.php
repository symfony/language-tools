<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;

interface SourceIndexStoreInterface
{
    /**
     * @return array<string, array{size: int, modifiedAt: int, hash: string, languageId: string, runtimeStructure: ?string, providers: array<string, string>}>
     */
    public function load(Project $project): array;

    /**
     * @param array<string, array{size: int, modifiedAt: int, hash: string, languageId: string, runtimeStructure: ?string, providers: array<string, string>}> $entries
     */
    public function save(Project $project, array $entries): void;
}
