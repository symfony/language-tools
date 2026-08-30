<?php

namespace Symfony\Lsp\Feature\Metadata;

final class FormDataClass
{
    public function __construct(
        public readonly string $formClass,
        public readonly string $dataClass,
    ) {
    }
}
