<?php

namespace Symfony\Lsp\Project;

final class ProjectCollectionChange
{
    /**
     * @param list<Project> $added
     * @param list<Project> $removed
     */
    public function __construct(
        public readonly array $added,
        public readonly array $removed,
    ) {
    }
}
