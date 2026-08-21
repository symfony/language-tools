<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<StimulusIndex> */
final class StimulusIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(StimulusIndex::class);
    }
}
