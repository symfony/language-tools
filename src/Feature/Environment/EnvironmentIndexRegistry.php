<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<EnvironmentIndex> */
final class EnvironmentIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(EnvironmentIndex::class);
    }
}
