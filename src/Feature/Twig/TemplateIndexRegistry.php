<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Index\AbstractProjectIndexRegistry;
use Symfony\Lsp\Project\Project;

/** @extends AbstractProjectIndexRegistry<TemplateIndex> */
final class TemplateIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct(private readonly DependencyInjectionSourceIndexRegistry $classIndexes)
    {
        parent::__construct();
    }

    protected function createIndex(Project $project): TemplateIndex
    {
        return new TemplateIndex($this->classIndexes->forProject($project));
    }
}
