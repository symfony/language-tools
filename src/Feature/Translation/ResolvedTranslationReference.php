<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Project\Project;

final readonly class ResolvedTranslationReference
{
    public function __construct(
        public TranslationReference $reference,
        public Project $project,
    ) {
    }
}
