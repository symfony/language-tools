<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<SecuritySourceIndex> */
final class SecuritySourceIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(SecuritySourceIndex::class);
    }
}
