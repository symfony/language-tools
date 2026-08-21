<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<MetadataIndex> */
final class MetadataIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(MetadataIndex::class);
    }
}
