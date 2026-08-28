<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<TwigPhpSymbolIndex> */
final class TwigPhpSymbolIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(TwigPhpSymbolIndex::class);
    }
}
