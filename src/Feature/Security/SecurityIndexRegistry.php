<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<SecurityIndex> */
final class SecurityIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(SecurityIndex::class);
    }
}
