<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<ConsoleIndex> */
final class ConsoleIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(ConsoleIndex::class);
    }
}
