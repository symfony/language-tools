<?php

namespace Symfony\Lsp\Project;

final class ProjectPathPolicy
{
    public const EXCLUDED_DIRECTORIES = ['.git', 'node_modules', 'var', 'vendor'];
}
