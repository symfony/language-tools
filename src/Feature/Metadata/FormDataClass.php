<?php

namespace Symfony\Lsp\Feature\Metadata;

final class FormDataClass
{
    public function __construct(
        private readonly string $formClass,
        private readonly string $dataClass,
    ) {
    }

    public function formClass(): string
    {
        return $this->formClass;
    }

    public function dataClass(): string
    {
        return $this->dataClass;
    }
}
