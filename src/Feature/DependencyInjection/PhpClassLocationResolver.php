<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class PhpClassLocationResolver
{
    public function __construct(
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly LspProtocolMapper $protocol,
    ) {
    }

    /**
     * @param iterable<string> $classNames
     *
     * @return list<array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}>
     */
    public function locations(Project $project, iterable $classNames): array
    {
        $index = $this->classIndexes->forProject($project);
        $declarations = [];
        foreach ($classNames as $className) {
            array_push($declarations, ...$index->classDeclarations($className));
        }

        return $this->protocol->locations($declarations);
    }
}
