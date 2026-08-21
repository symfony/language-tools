<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Index\AbstractProjectIndexRegistry;

/** @extends AbstractProjectIndexRegistry<TranslationIndex> */
final class TranslationIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(TranslationIndex::class);
    }
}
