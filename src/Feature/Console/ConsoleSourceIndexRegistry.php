<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<ConsoleSourceIndex> */
final class ConsoleSourceIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(ConsoleSourceIndex::class);
    }
}
