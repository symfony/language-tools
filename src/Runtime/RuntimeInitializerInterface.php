<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

interface RuntimeInitializerInterface
{
    public function initialize(Project $project): void;
}
