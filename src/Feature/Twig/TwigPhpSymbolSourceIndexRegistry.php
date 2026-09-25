<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<TwigPhpSymbolSourceIndex> */
final class TwigPhpSymbolSourceIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(TwigPhpSymbolSourceIndex::class);
    }
}
