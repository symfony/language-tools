<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<ConfigurationIndex> */
final class ConfigurationIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(ConfigurationIndex::class);
    }
}
