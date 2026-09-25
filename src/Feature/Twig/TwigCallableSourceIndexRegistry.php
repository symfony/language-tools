<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<TwigCallableSourceIndex> */
final class TwigCallableSourceIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(TwigCallableSourceIndex::class);
    }
}
