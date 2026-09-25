<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;

/** @template-covariant TIndex of object */
interface ProjectIndexRegistryInterface
{
    /** @return TIndex */
    public function forProject(Project $project): object;
}
